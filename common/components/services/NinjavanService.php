<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use yii\helpers\Json;

class NinjavanService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface, CountryRestrictionInterface
{
    public $id = 270;

    private const LABELS = [
        'ADDED_TO_SHIPMENT' => 'Departed Ninja Van warehouse',
        'ARRIVED_AT_DESTINATION_HUB' => 'Parcel is being processed at Ninja Van warehouse',
        'ARRIVED_AT_ORIGIN_HUB' => 'Parcel is being processed at Ninja Van warehouse',
        'ARRIVED_AT_TRANSIT_HUB' => 'Parcel is being processed at Ninja Van warehouse',
        'CANCEL' => 'Parcel delivery has been cancelled',
        'CREATE_ORDER' => 'Order created',
        'DELIVERY_FAILURE' => 'Delivery is unsuccessful',
        'DELIVERY_SUCCESS' => 'Successfully delivered',
        'added_to_shipment' => 'In Transit',
        'chrono_diali_sorting_facility' => 'Chrono Diali Sorting Facility',
        'delivery_failure' => 'For further assistance, kindly contact support_{country}@ninjavan.co',
        'ninja_van_sorting_facility' => 'Ninja Van Sorting Facility',
        'ninja_xpress_sorting_facility' => 'Ninja Xpress Sorting Facility',
        'DRIVER_INBOUND_SCAN' => 'Parcel is being delivered',
        'DRIVER_PICKUP_SCAN' => 'Successfully picked up from sender',
        'FORCED_SUCCESS' => 'Successfully delivered',
        'FROM_DP_TO_CUSTOMER' => 'Parcel successfully collected',
        'FROM_DP_TO_DRIVER' => 'Departed Parcel Dropoff Counter / Box',
        'FROM_DRIVER_TO_DP' => 'Parcel delivered to Parcel Collection Counter / Box',
        'FROM_SHIPPER_TO_DP' => 'Parcel dropped off at Parcel Dropoff Counter / Box',
        'HUB_INBOUND_SCAN' => 'Parcel is being processed at Ninja Van warehouse',
        'PARCEL_ROUTING_SCAN' => 'Parcel is being processed at Ninja Van warehouse',
        'RESCHEDULE' => 'Parcel delivery has been rescheduled',
        'RESUME' => 'Parcel delivery resumed',
        'ROUTE_INBOUND_SCAN' => 'Parcel is being processed at Ninja Van warehouse',
        'RTS' => 'Parcel is being returned to sender'
    ];

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
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://api.ninjavan.co/my/dash/1.2/public/orders?tracking_id=' . $trackNumber), $trackNumber, [
            RequestOptions::HEADERS => [
                'accept-encoding' => 'gzip, deflate',
                'referer' => 'https://www.ninjavan.co/',
                'x-requested-with' => 'XMLHttpRequest',
                'sec-ch-ua' => '"Google Chrome";v="87", " Not;A Brand";v="99", "Chromium";v="87"',
                'sec-ch-ua-mobile' => '?0',
                'sec-fetch-dest' => 'empty',
                'accept' => 'application/json',
                'sec-fetch-mode' => 'cors',
                'sec-fetch-site' => 'same-site',
                'x-nv-digest' => 'v3-' . $token
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = Json::decode($response->getBody()->getContents());

        if (!$json || !$json['id'] || !$json['created_at']) {
            return false;
        }

        $createdAt = Carbon::parse($json['created_at']);

        $result = new Parcel([
            'statuses' => [
                new Status([
                    'title' => self::LABELS['CREATE_ORDER'],
                    'date' => $createdAt->timestamp,
                    'dateVal' => $createdAt->toDateString(),
                    'timeVal' => $createdAt->toTimeString('minute')
                ])
            ],
            'sender' => $json['shipper_short_name'],
            'extraInfo' => [
                'Delivery Type' => $json['service_type']
            ]
        ]);

        foreach ($json['events'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['time']);

            $location = null;

            if (isset($checkpoint['data']['hub_name'])) {
                $location = self::LABELS[$checkpoint['data']['hub_name']] ?? $checkpoint['data']['hub_name'];
            }

            $result->statuses[] = new Status([
                'title' => self::LABELS[$checkpoint['type']] ?? $checkpoint['type'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $location
            ]);
        }

        return $result;
    }

    public function trackNumberRules(): array
    {
        return [
            'N[A-Z]{6}\d{11}',
            'NLMY[A-Z]\d{8}',
            'NV[A-Z]{7}\d{9}',
            'SPE\d{10}'
        ];
    }

    public function preheatCaptcha()
    {
        if ($token = \Yii::$app->AntiCaptcha->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => '6LfhacMUAAAAAEs5g_vdW96jr7b9QqZaXrwSElar',
            'websiteURL' => 'https://www.ninjavan.co/en-my/tracking',
            'type' => 'RecaptchaV3TaskProxyless',
            'minScore' => '0.9',
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

    public function restrictCountries()
    {
        return ['my'];
    }

    public function maxPreheatProcesses()
    {
        return 8;
    }
}