<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class AmazonService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 467;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://track.amazon.com/api/tracker/' . $trackNumber), $trackNumber);
    }


    public function trackNumberRules(): array
    {
        return [
            'TBA[0-9]{12}', //TBA053765442704
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $json = json_decode($data, true);

        if (!$json['eventHistory']) {
            return false;
        }

        $json = json_decode($json['eventHistory'], true);
        $result = new Parcel();

        foreach ($json['eventHistory'] as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint['eventTime']);

            $result->statuses[] = new Status([
                'title' => $checkpoint['eventCode'],
                'location' => $checkpoint['location']['countryCode'] . ' ' . $checkpoint['location']['city'] . ' ' . $checkpoint['location']['postalCode'],
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute')
            ]);

        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return ['us', 'ca'];
    }

}