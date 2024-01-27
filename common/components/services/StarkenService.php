<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use stdClass;

class StarkenService extends BaseService implements CountryRestrictionInterface, ValidateTrackNumberInterface, AsyncTrackingInterface, BatchTrackInterface, CaptchaPreheatInterface
{
    public $id = 188;
    private $recaptchaKey = '6LcF8vIUAAAAAA_c06RxnXfmFHDRQIw0jMzQLkzG';

    /** @var Client */
    private $antiCaptchaService;


    public function __construct($data = null)
    {
        $this->antiCaptchaService = \Yii::$container->get(Client::class);
        parent::__construct($data);
    }


    /**
     * @return array
     */
    public function restrictCountries()
    {
        return [
            'cl'
        ];
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
            '9\d{8}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($token = \common\models\redis\Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token);
    }

    /**
     * @param Response|stdClass $response
     * @param string $trackNumber
     * @return Parcel|bool
     */
    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        $result = new Parcel([
            'departureAddress' => $data['origin'],
            'destinationAddress' => $data['destination'],
            'sender' => $data['issuer_name'],
            'recipient' => $data['receiver_name'],
            'price' => $data['price']
        ]);

        foreach ($data['history'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['created_at']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['status'],
                'location' => $checkpoint['note'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return $result;
    }

    private function request($trackNumber, $token)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://gateway.starken.cl/tracking/orden-flete-dte/of/' . $trackNumber), $trackNumber, [
            RequestOptions::HEADERS => [
                'Authorization' => $token,
                'ContentType' => 'application/json',
                'Origin' => 'https://www.starken.cl',
                'Referer' => 'https://www.starken.cl/seguimiento',
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Encoding' => 'gzip, deflate',
            ],
            RequestOptions::CONNECT_TIMEOUT => 5
        ]);
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 10;
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://www.starken.cl/',
            'type' => 'RecaptchaV3TaskProxyless',
            'minScore' => '0.3',
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
        return 3;
    }

    public function maxPreheatProcesses()
    {
        return 8;
    }
}