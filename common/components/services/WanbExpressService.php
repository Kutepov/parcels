<?php

namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use yii;

class WanbExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface
{
    public $id = 162;
    private $recaptchaKey = '6LeQ3YMUAAAAANvTdE4Z3rrsEyJd5b6LbZyTsrU4';

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($token = \common\models\redis\Recaptcha::findTokenForProvider($this->id))) {
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

    private function request($trackNumber, $token)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://tracking.wanbexpress.com/TrackPoints/track'), $trackNumber, [
            RequestOptions::QUERY => ([
                'TrackingNumber' => $trackNumber,
                's' => '68222868671712929335277717747672736',
                't' => $token,
                '_' => time() . rand(111, 999)
            ]),
            RequestOptions::HEADERS => [
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => 'http://tracking.wanbexpress.com/?trackingNumbers=' . $trackNumber,
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Accept-Encoding' => 'gzip, deflate'
            ],

        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        if (!$data['Succeeded']) {
            return false;
        }

        $data = $data['Data'];

        $result = new Parcel([
            'departureCountryCode' => $data['Metadata']['OriginCountryCode'],
            'destinationCountryCode' => $data['Metadata']['DestinationCountryCode']
        ]);

        foreach ($data['TrackPoints'] as $checkpoint) {
            $dateTime = Carbon::parse(trim($checkpoint['Time'], 'Z'));

            $result->statuses[] = new Status([
                'title' => $checkpoint['Content'],
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
                'location' => $checkpoint['Location'],
            ]);
        }

        return $result;
    }


    public function trackNumberRules(): array
    {
        return [
            'WNB[A-Z]{2}[0-9]{10}YQ'
        ];
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'http://tracking.wanbexpress.com/',
        ]))) {
            return new \common\models\redis\Recaptcha([
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

}