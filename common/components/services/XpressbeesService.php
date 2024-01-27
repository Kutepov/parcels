<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use yii;

class XpressbeesService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, ManuallySelectedInterface, CountryRestrictionInterface, CaptchaPreheatInterface
{
    public $id = 258;

    private $recaptchaKey = '6LdLpL8ZAAAAAKpSUsaTdJqQ9qGw3BMnqEjq_3nC';
    private $mainInfo;

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

    public function track($trackNumber)
    {
        if (!($recaptcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $recaptcha->token)->wait();
    }

    private function request($trackNumber, $token)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.xpressbees.com/track?isawb=Yes&trackid=' . $trackNumber), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ], function (ResponseInterface $response) use ($trackNumber, $jar, $token) {
            $html = $response->getBody()->getContents();

            if (preg_match('#name="csrf_test_name" value="([a-f0-9]{32})" />#siu', $html, $csrf)) {
                return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.xpressbees.com/search'), $trackNumber, [
                    RequestOptions::COOKIES => $jar,
                    RequestOptions::FORM_PARAMS => [
                        'csrf_test_name' => $csrf[1],
                        'trackid' => $trackNumber,
                        'isawb' => 'Yes',
                        'captcha' => $token
                    ],
                    RequestOptions::HEADERS => [
                        'referer' => 'https://www.xpressbees.com/track?isawb=Yes&trackid=' . $trackNumber,
                        'x-requested-with' => 'XMLHttpRequest',
                        'accept' => 'application/json, text/javascript, */*; q=0.01'
                    ]
                ], function (ResponseInterface $response) use ($jar, $trackNumber) {
                    $json = json_decode($response->getBody()->getContents());
                    $this->mainInfo = $json->result[0];

                    return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.xpressbees.com/shipment-details'), $trackNumber, [
                        RequestOptions::FORM_PARAMS => [
                            'csrf_test_name' => $json->csrf_token,
                            'shipmentid' => $trackNumber
                        ],
                        RequestOptions::COOKIES => $jar,
                        RequestOptions::HEADERS => [
                            'referer' => 'https://www.xpressbees.com/track?isawb=Yes&trackid=' . $trackNumber,
                            'x-requested-with' => 'XMLHttpRequest',
                            'accept' => 'application/json, text/javascript, */*; q=0.01'
                        ]
                    ]);
                });
            }
            else {
                return new RejectedPromise('csrf not found');
            }
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $json = json_decode($data);

        if (!$json->shipdetails) {
            return false;
        }

        if ($this->mainInfo->EDD) {
            $estDate = Carbon::parse($this->mainInfo->EDD);
        }

        $result = new Parcel([
            'destinationAddress' => $this->mainInfo->FinalDestinationName,
            'departureAddress' => $this->mainInfo->ShipCity,
            'estimatedDeliveryTime' => $estDate->timestamp ?? null,
            'weight' => $this->mainInfo->PhysicalWeight * 1000
        ]);

        foreach ($json->shipdetails as $checkpoint) {
            $date = Carbon::parse(str_replace('-', '.', $checkpoint->CreatedDate) . ' ' . str_replace('.', ':', $checkpoint->Time));
            $title = $checkpoint->Description;
            if (preg_match('#Shipment Delivered received By:(.*?)#si', $title)) {
                $title = 'Shipment Delivered';
            }

            $result->statuses[] = new Status([
                'title' => $title,
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => implode(', ', array_filter([$checkpoint->HubLocation, $checkpoint->State]))
            ]);
        }

        return $result;
    }


    public function trackNumberRules(): array
    {
        return [];
    }

    public function restrictCountries()
    {
        return ['in'];
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://www.xpressbees.com/track?isawb=Yes',
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
        return 2;
    }

    public function maxPreheatProcesses()
    {
        return 3;
    }
}