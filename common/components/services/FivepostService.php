<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Yii;

class FivepostService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface
{
    public $id = 275;

    public $mainData;

    private $siteKey = '6Lf7XN8ZAAAAANuPZ9QKHDiuXUQvsvf6nRoNE-gj';

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function track($trackNumber, $extraFields = [])
    {
        if (!($recaptcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $recaptcha->token)->wait();
    }

    public function trackAsync($trackNumber, $extraFields = []): PromiseInterface
    {
        if (!($token = \common\models\redis\Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token);
    }

    public function request($trackNumber, $token)
    {
        //TODO: Не работает
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://node:3000'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'requestUrl' => 'https://fivepost.ru/',
                'method' => 'GET',
                'getCookies' => true,
            ],
        ], function (ResponseInterface $response) use ($trackNumber, $token) {
            foreach (json_decode($response->getBody()->getContents(), true) as $cookie) {
                if ($cookie['name'] === '_ym_uid') {
                    $sessionId = $cookie['value'];
                }
            }
            $requestJson = [
                "clientOrderId" => $trackNumber,
                'createDateFrom' => '2021-08-11T19:00:00.000Z',
                'g-recaptcha-response' => $token,
                'isEqualClientOrderId' => true,
                'sessionId' => $sessionId,
            ];
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://api-omni.x5.ru/api/v1/internal-frontend-api/search-orders-by-filters'), $trackNumber, [
                    RequestOptions::HEADERS => [
                        'Accept' => 'application/json',
                        'Accept-Encoding' => 'gzip, deflate, br',
                        'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                        'Connection' => 'keep-alive',
                        'Content-Type' => 'application/json',
                        'Host' => 'api-omni.x5.ru',
                        'Origin' => 'https://fivepost.ru',
                        'Referer' => 'https://fivepost.ru/',
                        'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="97", "Chromium";v="97"',
                        'sec-ch-ua-mobile' => '?0',
                        'sec-ch-ua-platform' => '"Windows"',
                        'Sec-Fetch-Dest' => 'empty',
                        'Sec-Fetch-Mode' => 'cors',
                        'Sec-Fetch-Site' => 'cross-site',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.99 Safari/537.36',
                    ],
                    RequestOptions::JSON => $requestJson,
                ]
            );
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        //TODO: Не работает
        foreach (json_decode($response->getBody()->getContents(), true) as $checkpoint) {
            $date = Carbon::parse($checkpoint['changeDate']);

            $statuses[] = new Status([
                'title' => $checkpoint['executionStatus'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return isset($statuses) ? new Parcel(['statuses' => $statuses, 'departureAddress' => $this->mainData['receiverLocationAddress']]) : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{9}-[0-9]{1}' // 655661377-0
        ];
    }


    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->siteKey,
            'websiteURL' => 'https://fivepost.ru/'
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