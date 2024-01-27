<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class Lion360Service extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 400;

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
            '[A-Z]{5}[0-9]{10}[A-Z]{2}' // WSHMX1711600887YQ
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://track.360lion.com:8080/toms/track/waybillDetail?shipperHawbcode=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dataJson = json_decode($data, true);

        if ($dataJson['code'] === '1') {
            return false;
        }

        $result = new Parcel();

        foreach ($dataJson['data'] as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint['trackOccurDate']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['trackDescription'],
                'date' => $dateTime->timestamp,
                'location' => $checkpoint['trackLocation'],
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}