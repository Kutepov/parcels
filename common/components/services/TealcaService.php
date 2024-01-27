<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class TealcaService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 452;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.tealca.com/api/v1/tracking/office/guia/' . $trackNumber), $trackNumber);
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{6,9}', //40756971
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents());

        if (!isset($json[0])) {
            return false;
        }

        $result = new Parcel();

        foreach ($json[0] as $key => $field) {
            if ($key === 'tracking') {
                foreach ($field as $checkpoint) {
                    foreach ($checkpoint as $keyField => $checkpointField) {
                        switch ($keyField) {
                            case 'fecha':
                                $dateTime = Carbon::parse($checkpointField);
                                break;
                            case 'status':
                                $title = $checkpointField;
                                break;
                            case 'destino':
                                $location = $checkpointField;
                                break;
                        }
                    }
                    $result->statuses[] = new Status([
                        'title' => $title,
                        'location' => $location,
                        'date' => $dateTime->timestamp,
                        'dateVal' => $dateTime->toDateString(),
                        'timeVal' => $dateTime->toTimeString('minute'),

                    ]);
                }
            }

        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            've',
            'co',
            'us',
        ];
    }
}