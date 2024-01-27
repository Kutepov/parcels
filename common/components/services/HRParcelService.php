<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class HRParcelService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 463;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.hrparcel.com/wp-admin/admin-ajax.php?action=api-hrparcel-tracking&api-method=get&api-action=barcode&q=' . $trackNumber), $trackNumber);
    }


    public function trackNumberRules(): array
    {
        return [
            'KPKRITKEC000[0-9]{6}', //KPKRITKEC000112606
            '[0-9]{14}', //09198279300158
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);

        if (!count($json['steps'])) {
            return false;
        }

        $result = new Parcel();

        $result->recipient = $json['response']['RecipientAddress']['Name'];
        $result->sender = $json['response']['SenderAddress']['Name'];
        $result->destinationAddress = $json['shipment']['recipient']['town'];
        $result->departureAddress = $json['shipment']['sender']['town'];

        foreach ($json['steps'] as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint['originalDate']);

            $result->statuses[] = new Status([
                'title' => $checkpoint['status'],
                'location' => $checkpoint['town'],
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