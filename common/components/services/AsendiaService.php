<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class AsendiaService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 224;
    private $trackingKey = 'AE654169-0B14-45F9-8498-A8E464E13D26';
    private $authToken = 'Q3VzdEJyYW5kLlRyYWNraW5nQGFzZW5kaWEuY29tOjJ3cmZzelk4cXBBQW5UVkI=';
    private $apiKey = '32337AB0-45DD-44A2-8601-547439EF9B55';

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://a1reportapi.asendiaprod.com/api/A1/TrackingBranded/Tracking?trackingKey=' . $this->trackingKey . '&trackingNumber=' . $trackNumber), $trackNumber, [
            RequestOptions::HEADERS => [
                'authorization' => 'Basic ' . $this->authToken,
                'content-type' => 'application/json',
                'x-asendiaone-apikey' => $this->apiKey,
                'referer' => 'https://a1.asendiausa.com/'
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
            $data = json_decode($response->getBody()->getContents(), true);

            if (!$data['trackingBrandedSummary']) {
                return false;
            }

            $result = new Parcel([
                'destinationCountryCode' => $data['trackingBrandedSummary']['destinationCountryIso2']
            ]);

            foreach ($data['trackingBrandedDetail'] as $checkpoint) {
                $date = Carbon::parse($checkpoint['eventOn']);

                $result->statuses[] = new Status([
                    'title' => $checkpoint['eventDescription'],
                    'location' => implode(', ', array_filter([$checkpoint['eventLocationDetails']['city'], $checkpoint['eventLocationDetails']['countryName']])),
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute')
                ]);
            }

        return $result;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackNumberRules(): array
    {
        return [];
    }
}