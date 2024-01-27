<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Yii;

class UkrposhtaService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface, CountryRestrictionInterface
{
    private $recaptchaKey = '6Lf1DSwUAAAAAGnxZN2KrWcwc5KZdrhwmEPVu0It';

    public $id = 438;

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


    public function trackNumberRules(): array
    {
        return [
            '[0-9]{10}' //0505046438129
        ];
    }

    public function request($trackNumber, $token): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://track.ukrposhta.ua/php/track.php'), $trackNumber, [
            RequestOptions::HEADERS => [
                'authority' => 'track.ukrposhta.ua',
                'method' => 'POST',
                'path' => '/php/track.php',
                'scheme' => 'https',
                'accept' => 'application/json, text/plain, */*',
                'accept-encoding' => 'gzip, deflate, br',
                'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'content-type' => 'application/json;charset=UTF-8',
                'origin' => 'https://track.ukrposhta.ua',
                'referer' => 'https://track.ukrposhta.ua/tracking_UA.html?barcode=' . $trackNumber,
                'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="96", "Google Chrome";v="96"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'sec-fetch-dest' => 'empty',
                'sec-fetch-mode' => 'cors',
                'sec-fetch-site' => 'same-origin',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.93 Safari/537.36',
            ],
            RequestOptions::JSON => [
                'barcode' => $trackNumber,
                'g-recaptcha-response' => $token,
                'lang' => "UA"
            ]
        ]);

    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $json = json_decode($data, true);

        if (!count($json['result'])) {
            return false;
        }

        $result = new Parcel();
        $result->destinationAddress = $json['from_to']['city_to'];
        $result->departureAddress = $json['from_to']['city_from'];

        foreach ($json['result'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['gtt_date']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['gtt_event_name'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $checkpoint['gtt_country'] . ' ' . $checkpoint['gtt_name']
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://track.ukrposhta.ua/tracking_UA.html',
        ]))) {
            return new Recaptcha([
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
        return 3;
    }

    public function maxPreheatProcesses()
    {
        return 8;
    }

    public function restrictCountries()
    {
        return [
            'uk',
            'us',
            'ru'
        ];
    }
}