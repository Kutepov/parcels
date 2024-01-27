<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class Pilotdelivers extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{9}' // 370608820
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://wwwapi.pilotdelivers.com/track/'.base64_encode($trackNumber).'/orgzip/0/dstzip/0/custnum/0'), $trackNumber,
        [
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json; charset=utf-8',
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dataJson = json_decode($data);

        if (!isset($dataJson->TrackingResponse[0]->TrackingInfo->TrackingEventHistory)) {
            return false;
        }

        $result = new Parcel();
        foreach ($dataJson->TrackingResponse[0]->TrackingInfo->TrackingEventHistory as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint->EventDateTime);
            $result->statuses[] = new Status([
                'title' => $checkpoint->EventCodeDesc,
                'location' => $checkpoint->EventLocation->City,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}