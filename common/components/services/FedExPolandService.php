<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class FedExPolandService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 446;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://api1.emea.fedex.com/fds2-tracking/trck-v1/info?trackingKey=' . $trackNumber), $trackNumber, [
            RequestOptions::HEADERS => [
                'apikey' => 'l7xx492b4e2b8682483c979222bdd33216cf'
            ]
        ]);
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{13}' // 6231838792544
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);

        $result = new Parcel();
        $result->weight = $json['weight'];
        $result->destinationAddress = $json['deliveryDepot'];
        $result->departureAddress = $json['shipmentDepot'];

        foreach ($json['events'] as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint['eventDate']);

            $result->statuses[] = new Status([
                'title' => $checkpoint['eventName'],
                'location' => $checkpoint['depot'],
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'us',
            'ca',
            'mx',
            'uk',
            'ch'
        ];
    }
}