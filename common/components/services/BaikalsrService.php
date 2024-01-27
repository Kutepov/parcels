<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class BaikalsrService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 429;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://api.baikalsr.ru/v1/tracking?number=' . $trackNumber), $trackNumber, [
            RequestOptions::HEADERS => [
                'Authorization' => 'Basic ZjRiMDY2YmZlMTM5MmEzODc3M2FmMDIwZGNkMzY4MDI6',
            ],
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        if (isset($data['error'])) {
            return false;
        }

        $result = new Parcel();
        $result->departureAddress = $data['departure'];
        $result->destinationAddress = $data['destination'];
        $date = Carbon::parse($data['status']['date']);
        $result->statuses[] = (new Status([
            'date' => $date->timestamp,
            'dateVal' => $date->toDateString(),
            'timeVal' => $date->toTimeString('minute'),
            'title' => $data['status']['text'],
        ]));

        return $result;
    }

    public function trackNumberRules(): array
    {
        return [
            'ОМС-[0-9]{6}' //ОМС-017427
        ];
    }

    public function restrictCountries()
    {
        return [
            'ru'
        ];
    }

}