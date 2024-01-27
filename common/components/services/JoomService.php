<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class JoomService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 75;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' // UC983831330HK
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://api-logistics.joom.com/api/0.1/trackings/' .  $trackNumber . '?lang=en'), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $resultJson = json_decode($data, true);

        if (!isset($resultJson['data']['checkpoints'])) {
            return false;
        }

        $result = new Parcel();

        $result->destinationCountryCode = $resultJson['data']['destinationCountry'];
        $result->departureCountryCode = $resultJson['data']['sourceCountry'];

        foreach ($resultJson['data']['checkpoints'] as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint['time']);
            $location = isset($checkpoint['country']) ? $checkpoint['country'] : '';
            $location .= isset($checkpoint['location']) ? $checkpoint['location'] : '';
            $result->statuses[] = new Status([
                'title' => $checkpoint['message'],
                'location' => $location,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute')
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'us'
        ];
    }
}