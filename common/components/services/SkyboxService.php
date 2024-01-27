<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class SkyboxService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 445;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://get.attskybox.com/search_by_tracking.json?tracking=' . $trackNumber), $trackNumber);
    }

    public function trackNumberRules(): array
    {
        return [
            'EB51[0-9]{7}TH' // EB519046762TH
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);

        if (is_null($json['order'])) {
            return false;
        }

        $result = new Parcel();
        $result->destinationAddress = $json['order']['destination'];
        $result->departureAddress = $json['order']['origin'];

        foreach ($json['order']['histories'] as $checkpoint) {
            $dateTime = Carbon::parse(str_replace('/', '-', $checkpoint['created_at']));

            $result->statuses[] = new Status([
                'title' => $checkpoint['action'],
                'location' => $checkpoint['place'],
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
            'th',
        ];
    }
}