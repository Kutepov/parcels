<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use yii\helpers\Json;
use Yii;

class PosMalaysiaService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface, BatchTrackInterface
{
    public $id = 85;

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

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($token = \common\models\redis\Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token);
    }

    private function request($trackNumber, $token)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.pos.com.my/'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ], function (ResponseInterface $response) use ($trackNumber, $jar, $token) {
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.pos.com.my/calculation/result/result/'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::JSON => [
                    'g-recaptcha-response' => $token,
                    'token' => $token,
                    'trackingId' => implode(';', (array)$trackNumber)
                ],
                RequestOptions::HEADERS => [
                    'authority' => 'www.pos.com.my',
                    'method' => 'POST',
                    'path' => '/calculation/result/result/',
                    'scheme' => 'https',
                    'accept' => '*/*',
                    'accept-encoding' => 'gzip, deflate, br',
                    'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'content-type' => 'application/json; charset=UTF-8',
                    'origin' => 'https://www.pos.com.my',
                    'referer' => 'https://www.pos.com.my/',
                    'sec-ch-ua' => ' " Not A;Brand";v="99", "Chromium";v="98", "Google Chrome";v="98"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'sec-fetch-dest' => 'empty',
                    'sec-fetch-mode' => 'cors',
                    'sec-fetch-site' => 'same-origin',
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/98.0.4758.82 Safari/537.36',
                    'x-newrelic-id' => 'VgEHWFVQDRAFUFBbDgUOVFc=',
                    'x-requested-with' => 'XMLHttpRequest',
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        //TODO: Отвечает {"errors":true,"message":"reCAPTCHA verification failed, please try again."}
        $json = Json::decode($response->getBody()->getContents());

        if ((isset($json['errors']) && $json['errors']) || !$json['ResponseTrackData']) {
            return false;
        }

        $trackingData = Json::decode($json['ResponseTrackData']);

        $result = new Parcel();

        foreach ($trackingData as $track) {
            if ($track['connoteNo'] === $trackNumber) {
                foreach ($track['result'] as $checkpoint) {
                    $date = Carbon::parse($checkpoint['date']);
                    if (in_array($checkpoint['process'], ['Can\'t Find any Event', 'No record found'])) {
                        return false;
                    }
                    $result->statuses[] = new Status([
                        'title' => $checkpoint['process'],
                        'date' => $date->timestamp,
                        'dateVal' => $date->toDateString(),
                        'timeVal' => $date->toTimeString('minute')
                    ]);
                }
            }
        }

        return $result;
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}MY',
            'B[A-Z]{1}[0-9]{9}MY',
            'C[A-Z]{1}[0-9]{9}MY',
            'E[A-Z]{1}[0-9]{9}MY',
            'F[A-Z]{1}[0-9]{9}MY',
            'L[A-Z]{1}[0-9]{9}MY',
            'R[A-Z]{1}[0-9]{9}MY',
            'S[A-Z]{1}[0-9]{9}MY',
            'U[A-Z]{1}[0-9]{9}MY',
            'V[A-Z]{1}[0-9]{9}MY',
            '[A-Z]{3}[0-9]{8}MY',
            'GEG[A-Z]{2}[0-9]{8}',
            'SYB[A-Z]{2}[0-9]{8}',
            'SYL[A-Z]{2}[0-9]{8}',
            'YLC[A-Z]{2}[0-9]{8}',
            '[A-Z]{3}[0-9]{9}MY',
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }


    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => '6LfbgNcZAAAAAFszd4kWQS77ZiIzh9EyIA4Ilo3Y',
            'websiteURL' => 'https://www.pos.com.my/',
            'type' => 'RecaptchaV2TaskProxyless',
            'minScore' => '0.9',
        ]))) {
            return new \common\models\redis\Recaptcha([
                'token' => $token
            ]);
        }

        return null;
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 5;
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
}