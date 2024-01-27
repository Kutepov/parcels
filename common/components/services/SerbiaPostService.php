<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;
use Yii;

class SerbiaPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface
{
    protected $url = 'https://www.posta.rs/cir/alati/pracenje-posiljke.aspx';

    public $id = 158;

    private $recaptchaKey = '6Ldw17cjAAAAAOK_ZlGdpjUplNDaBXtph_IGdBz2';

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}RS',
            'B[A-Z]{1}[0-9]{9}RS',
            'C[A-Z]{1}[0-9]{9}RS',
            'E[A-Z]{1}[0-9]{9}RS',
            'L[A-Z]{1}[0-9]{9}RS',
            'P[A-Z]{1}[0-9]{9}RS',
            'R[A-Z]{1}[0-9]{9}RS',
            'S[A-Z]{1}[0-9]{9}RS',
            'V[A-Z]{1}[0-9]{9}RS',
            'RA54[0-9]{7}QM',
            'RA56[0-9]{7}QM',
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($token = Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token);
    }

    public function track($trackNumber)
    {
        if (!($recaptcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $recaptcha->token)->wait();
    }

    public function request($trackNumber, $token): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', $this->url), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($trackNumber, $token, $jar) {
            $dom = new Crawler($response->getBody()->getContents());
            $__EVENTTARGET = $dom->filterXPath('//input[@value="Пратите"]')->attr('name');
            $__EVENTARGUMENT = $dom->filterXPath('//input[@name="__EVENTARGUMENT"]')->attr('value');
            $__VIEWSTATE = $dom->filterXPath('//input[@name="__VIEWSTATE"]')->attr('value');
            $__VIEWSTATEGENERATOR = $dom->filterXPath('//input[@name="__VIEWSTATEGENERATOR"]')->attr('value');
            $__EVENTVALIDATION = $dom->filterXPath('//input[@name="__EVENTVALIDATION"]')->attr('value');

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.posta.rs/cir/alati/pracenje-posiljke.aspx'), $trackNumber, [
                RequestOptions::FORM_PARAMS => [
                    '__EVENTTARGET' => $__EVENTTARGET,
                    '__EVENTARGUMENT' => $__EVENTARGUMENT,
                    '__VIEWSTATE' => $__VIEWSTATE,
                    '__VIEWSTATEGENERATOR' => $__VIEWSTATEGENERATOR,
                    '__EVENTVALIDATION' => $__EVENTVALIDATION,
                    'ctl00$ctl00$cphMain$cphAlati$pracenjeposiljkeusercontrol$txtPosiljka' => $trackNumber,
                    'ctl00$ctl00$cphMain$cphAlati$pracenjeposiljkeusercontrol$tbCaptchaToken' => $token
                ],
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Cache-Control' => 'max-age=0',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Host' => 'www.posta.rs',
                    'Origin' => 'https://www.posta.rs',
                    'Referer' => 'https://www.posta.rs/cir/alati/pracenje-posiljke.aspx',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'same-origin',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
                    'sec-ch-ua' => '"Not.A/Brand";v="8", "Chromium";v="114", "Google Chrome";v="114"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        preg_match('/<table.*?>(.*?)<\/table>/is', $data, $matches);

        if (!empty($matches[1])) {
            $statuses = [];

            preg_match_all('/<tr>(.*?)<\/tr>/is', $data, $items);

            foreach ($items[1] as $key => $item) {
                if ($key == 0) {
                    continue;
                }

                preg_match_all('/<td>(.*?)<\/td>/is', $item, $matches);

                $date = Carbon::parse(trim($matches[1][0]));
                $statuses[] = new Status([
                    'title' => trim($matches[1][2]),
                    'location' => trim($matches[1][1]),
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute')
                ]);
            }

            return new Parcel([
                'statuses' => $statuses
            ]);
        }

        return false;
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptchaV3([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://www.posta.rs/cir/alati/pracenje-posiljke.aspx',
        ]))) {
            return new Recaptcha([
                'token' => $token
            ]);
        }

        return null;
    }

    public function captchaLifeTime(): int
    {
        return 120;
    }


    public function recaptchaVersion()
    {
        return 3;
    }

    public function maxPreheatProcesses()
    {
        return 8;
    }

    public function restrictCountries()
    {
        return [
            'ro', 'bg', 'md'
        ];
    }
}