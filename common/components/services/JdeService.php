<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class JdeService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 420;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '22[0-9]{14}' //2252628771656583
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://i.jde.ru/vM/OrderTrack?ttn=' . $trackNumber .'&pincode=1'), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $json = json_decode($data, true);

        $result = new Parcel();
        $result->departureCountry = $json['MST_NAME'];

        foreach ($json['result'] as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint['DTIME']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['TEXT'],
                'date' => $dateTime->timestamp,
                'location' => $checkpoint['MST_NAME'],
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'ru'
        ];
    }
}