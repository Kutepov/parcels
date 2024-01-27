<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class DelhiveryService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 230;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://dlv-api.delhivery.com/v3/track?wbn=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        if (stristr($data['message'], 'ERROR')) {
            return false;
        }

        $data = $data['data'][0];

        try {
            $estimatedDeliveryTime = Carbon::parse($data['estimatedDate']);
        } catch (\Throwable $e) {
            $estimatedDeliveryTime = null;
        }

        $result = new Parcel([
            'estimatedDeliveryTime' => $estimatedDeliveryTime->timestamp,
            'destinationAddress' => $data['destination'] ?? null,
            'description' => $data['productName'] ?? null
        ]);


        foreach ($data['scans'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['scanDateTime']);

            $result->statuses[] = new Status([
                'title' => $checkpoint['scanNslRemark'],
                'location' => $checkpoint['cityLocation'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackNumberRules(): array
    {
        return [];
    }

    public function restrictCountries()
    {
        return ['in'];
    }
}