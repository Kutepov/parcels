<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class BringService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 182;
    private $url = 'https://www.bring.no/english';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}\d{9}NO',
            '3\d{17}',
            '7\d{16}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://tracking.bring.com/tracking/api/fetch?query=' . $trackNumber . '&lang=en'), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $tracking = json_decode($response->getBody()->getContents(), true);

        $result = $tracking['consignmentSet'][0];
        if (isset($result['error'])) {
            return false;
        }

        $package = $result['packageSet'][0];

        $parcel = new Parcel([
            'weight' => $result['totalWeightInKgs'] * 1000,
            'sender' => $package['senderName'],
            'recipient' => $package['recipientName'],
            'departureAddress' => $package['senderAddress'],
            'destinationAddress' => $package['recipientAddress']
        ]);

        foreach ($package['eventSet'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['dateIso']);

            $location = implode(' ', [$checkpoint['country'] ?? null, $checkpoint['city'] ?? null, $checkpoint['postalCode'] ?? null]);
            $parcel->statuses[] = new Status([
                'title' => $checkpoint['description'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => trim($location),
            ]);
        }

        return $parcel;
    }
}