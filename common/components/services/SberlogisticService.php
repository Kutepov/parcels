<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class SberlogisticService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 427;

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        }
        catch (\Exception $exception) {}
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://sberlogistics.ru/api'), $trackNumber, [
            RequestOptions::JSON => [
                'method' => 'getTracking',
                'params' => ['tracking_number' => $trackNumber]
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        $result = new Parcel();
        $result->weight = $data['data'][0]['attributes']['weight_and_dimensions']['weight'];
        $result->destinationCountry = $data['data'][0]['attributes']['sender']['address']['city'];
        $result->departureCountry = $data['data'][0]['attributes']['recipient']['address']['city'];

        foreach ($data['data'][0]['statuses'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['global_time']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['status_description'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $checkpoint['location']['facility']['address'] ?? '',
            ]);

        }
        return $result;
    }

    public function trackNumberRules(): array
    {
        return ['RP[0-9]{9}']; //RP239510340
    }

    public function restrictCountries(): array
    {
        return ['ru'];
    }
}