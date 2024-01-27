<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class AliPostService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 462;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://alilpost.com/ajax/ru/'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'task' => 'getstatus',
                'order_number' => $trackNumber,
            ]
        ]);
    }


    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}', //CV000030055IL
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        if ($data === 'error') {
            return false;
        }

        $json = json_decode($data, true);

        $result = new Parcel();

        foreach ($json['data'] as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint['date']);

            $result->statuses[] = new Status([
                'title' => $checkpoint['message'],
                'location' => $checkpoint['country'] . ' ' . $checkpoint['city'],
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute')
            ]);

        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [];
    }

}