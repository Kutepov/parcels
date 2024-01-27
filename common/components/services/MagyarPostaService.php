<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\AntiCaptcha\Tasks\ReCaptcha;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use Yii;

class MagyarPostaService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface
{
    public $captcha = true;
    private $recaptchaKey = '6LdYozgUAAAAANh0eNKcM7BOrhVGhem_woUQKz7Z';

    public $id = 102;
    private $url = 'https://www.posta.hu';

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }


    public function track($trackNumber)
    {
        if (!($recaptcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $recaptcha->token)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}HU',
            'C[A-Z]{1}[0-9]{9}HU',
            'E[A-Z]{1}[0-9]{9}HU',
            'L[A-Z]{1}[0-9]{9}HU',
            'R[A-Z]{1}[0-9]{9}HU',
            'S[A-Z]{1}[0-9]{9}HU',
            'U[A-Z]{1}[0-9]{9}HU',
            'V[A-Z]{1}[0-9]{9}HU',
            'RL[0-9]{14}HU',
            'JJH30AAAAA[A-Z0-9]{2}[0-9]{8}'
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
        if (!($token = \common\models\redis\Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token);
    }

    private function request($trackNumber, $token)
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.posta.hu/ikrnyomkovproxy/IKRNyomkovetes/Handlers/AjaxController.aspx'), $trackNumber, [
            RequestOptions::TIMEOUT => 30,
            RequestOptions::COOKIES => $jar = new CookieJar(),
            RequestOptions::CONNECT_TIMEOUT => 30,
            RequestOptions::FORM_PARAMS => [
                'action' => 'Business:PERFORM_SEARCH',
                'parameters' => json_encode([
                    'search_value' => $trackNumber,
                    'captcha_Response' => $token
                ]),
                'Session' => ''
            ]
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.posta.hu/ikrnyomkovproxy/default.aspx?type=result'), $trackNumber, [
                RequestOptions::HEADERS => [
                    "Accept" => "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9",
                    "Accept-Encoding" => "gzip, deflate, br",
                    "Accept-Language" => "ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7",
                    "Connection" => "keep-alive",
                    "Host" => "www.posta.hu",
                    "Referer" => "https://www.posta.hu/ikrnyomkovproxy/",
                    "sec-ch-ua" => '"Google Chrome";v="93", " Not;A Brand";v="99", "Chromium";v="93"',
                    "sec-ch-ua-mobile" => "?0",
                    "sec-ch-ua-platform" => '"Windows"',
                    "Sec-Fetch-Dest" => "iframe",
                    "Sec-Fetch-Mode" => "navigate",
                    "Sec-Fetch-Site" => "same-origin",
                    "Sec-Fetch-User" => "?1",
                    "Upgrade-Insecure-Requests" => 1,
                    "User-Agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.82 Safari/537.36"
                ],
                RequestOptions::TIMEOUT => 30,
                RequestOptions::CONNECT_TIMEOUT => 30,
                RequestOptions::COOKIES => $jar,
                RequestOptions::QUERY => [
                    'type' => 'result'
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($data);

        $checkpoints = $dom->find('.more_details_item');

        if ($checkpoints->count() === 0) {
            return false;
        }

        $statuses = [];
        foreach ($checkpoints as $checkpoint) {
            $dateStr = $checkpoint->find('.data_more_1')->find('div')->text();
            [$year, $month, $day, $time] = mb_split('. ', $dateStr);
            $date = Carbon::parse($year . '-' . $month . '-' . $day . ' ' . $time);

            $statuses[] = new Status([
                'title' => $checkpoint->find('.data_more_3')->find('div')->text(),
                'location' => $checkpoint->find('.data_more_4')->find('div')->text(),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]);
        }

        return new Parcel([
            'statuses' => $statuses
        ]);
    }


    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://www.posta.hu/nyomkovetes/nyitooldal',
        ]))) {
            return new \common\models\redis\Recaptcha([
                'token' => $token
            ]);
        }

        return null;
    }

    public function captchaLifeTime(): int
    {
        return 115;
    }

    public function recaptchaVersion()
    {
        return 2;
    }

    public function maxPreheatProcesses()
    {
        return 8;
    }
}