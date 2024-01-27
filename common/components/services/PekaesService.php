<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class PekaesService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 430;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://strefaklienta.pekaes.geodis.pl/pl/?ltlHistory=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $jsonString = $response->getBody()->getContents();

        if ($jsonString === 'null') {
            return false;
        }

        $data = json_decode($jsonString, true);

        $result = new Parcel();

        foreach ($data as $checkpoint) {
            $date = Carbon::parse($checkpoint['TS_TIME']);
            $result->statuses[] = new Status([
                'location' => $checkpoint['TS_SCAN_PLACE'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'title' => $checkpoint['TS_SCAN_TEXT'],
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackNumberRules(): array
    {
        return [
            'PL[0-9]{16}'
        ]; //PL2559610061744006
    }

    public function restrictCountries()
    {
        return ['pl'];
    }
}