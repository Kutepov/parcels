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
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use Yii;

class PatrusService extends BaseService implements ValidateTrackNumberInterface, ManuallySelectedInterface, CountryRestrictionInterface, CaptchaPreheatInterface, AsyncTrackingInterface
{
    public $id = 303;
    private $recaptchaKey = '6LdjoNgUAAAAAE5kJZ-SxiQ49EI4L_IsiLS-y276';

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($token = Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token);
    }

    private function request($trackNumber, $token)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://portal.patrus.com.br/tracking/cli/e/Tracking/Info.aspx/cli/e/Tracking/Info.aspx?CGC=&TRACKINGNUMBER=' . $trackNumber . '&TIPO=R'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($trackNumber, $jar, $token) {

            $resData = $response->getBody()->getContents();
            $mainData = (new Dom())->loadStr($resData);
            $data = (new Dom())->loadStr($mainData);

            $postData['__EVENTTARGET'] = $data->find('*[name="__EVENTTARGET"]')->getAttribute('value');
            $postData['__EVENTARGUMENT'] = $data->find('*[name="__EVENTARGUMENT"]')->getAttribute('value');
            $postData['__VIEWSTATE'] = $data->find('*[name="__VIEWSTATE"]')->getAttribute('value');
            $postData['__VIEWSTATEGENERATOR'] = $data->find('*[name="__VIEWSTATEGENERATOR"]')->getAttribute('value');
            $postData['__SCROLLPOSITIONX'] = $data->find('*[name="__SCROLLPOSITIONX"]')->getAttribute('value');
            $postData['__SCROLLPOSITIONY'] = $data->find('*[name="__SCROLLPOSITIONY"]')->getAttribute('value');
            $postData['g-recaptcha-response'] = $token;
            $postData['__RequestVerificationToken'] = $data->find('*[name="__RequestVerificationToken"]')->getAttribute('value');

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://portal.patrus.com.br/tracking/cli/e/Tracking/Info.aspx/cli/e/Tracking/Info.aspx?CGC=&TRACKINGNUMBER=' . $trackNumber . '&TIPO=R'), $trackNumber, [
                RequestOptions::HEADERS => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Cache-Control' => 'max-age=0',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Host' => 'portal.patrus.com.br',
                    'Origin' => 'https://portal.patrus.com.br',
                    'Referer' => 'https://portal.patrus.com.br/tracking/cli/e/Tracking/Info.aspx/cli/e/Tracking/Info.aspx?CGC=&TRACKINGNUMBER=' . $trackNumber . '&TIPO=R',
                    'sec-ch-ua' => '"Google Chrome";v="93", " Not;A Brand";v="99", "Chromium";v="93"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => 'Windows',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'same-origin',
                    'Upgrade-Insecure-Requests' => '1',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.82 Safari/537.36'
                ],
                RequestOptions::DELAY => rand(1000, 1500),
                RequestOptions::COOKIES => $jar,
                RequestOptions::FORM_PARAMS => $postData,
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = (new Dom())->loadStr($data);

        $result = new Parcel();

        foreach ($dom->find('.timeline-body') as $checkpoint) {
            $dateStr = str_replace('/', '.', $checkpoint->find('.timeline-body-time', 0)->text);
            $title = $checkpoint->find('.timeline-body-alerttitle', 0)->text;

            $dateTime = Carbon::parse($dateStr);
            $result->statuses[] = new Status([
                'title' => $title,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        foreach ($dom->find('.form-group') as $checkpoint) {
            $result->extraInfo[html_entity_decode($checkpoint->find('.label-title', 0)->text)] = $checkpoint->find('.form-control', 0)->getAttribute('value');
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function track($trackNumber)
    {
        if (!($recaptcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $recaptcha->token)->wait();
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptchaV3([
            'websiteKey' => $this->recaptchaKey,
            'pageAction' => 'TrackingsController',
            'websiteURL' => 'https://portal.patrus.com.br/tracking/cli/e/Tracking/Info.aspx/cli/e/Tracking/Info.aspx',
        ]), true)) { //TODO V3
            return new Recaptcha([
                'token' => $token
            ]);
        }

        return null;
    }

    public function trackNumberRules(): array
    {
        return [
            'ML[0-9]{24}[A-Z]{3}' //ML050917831891040508864134TFL
        ];
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
        return ['br'];
    }
}