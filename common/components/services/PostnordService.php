<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class PostnordService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 401;

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        }
        catch (\Exception $exception) {}
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' // LE314352411SE
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://api2.postnord.com/rest/shipment/v5/trackandtrace/ntt/shipment/recipientview?id=' . $trackNumber), $trackNumber, [
            RequestOptions::HEADERS => [
                'x-bap-key' => ' web-ncp'
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dataJson = json_decode($data, true);

        if (!count($dataJson['TrackingInformationResponse']['shipments'])) {
            return false;
        }

        $result = new Parcel();
        $dataJson = $dataJson['TrackingInformationResponse']['shipments'][0];
        $result->destinationCountryCode = $dataJson['consignee']['address']['countryCode'];
        $result->destinationCountry = $dataJson['consignee']['address']['country'];

        foreach ($dataJson['items'][0]['events'] as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint['eventTime']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['eventDescription'],
                'date' => $dateTime->timestamp,
                'location' => $checkpoint['location']['country'],
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}