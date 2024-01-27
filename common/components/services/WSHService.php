<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class WSHService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 99;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'WSH[A-Z]{2}[0-9]{10}YQ' // NX069877870BR
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://track.360lion.com:8080/toms/track/waybillDetail?shipperHawbcode=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        if ($data['status'] !== 'success') {
            return false;
        }

        foreach ($data['data'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['trackOccurDate']);

            $statuses[] = new Status([
                'title' => $checkpoint['trackDescriptionEn'],
                'location' => $checkpoint['trackLocation'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return isset($statuses) ? new Parcel(['statuses' => $statuses]) : false;
    }
}