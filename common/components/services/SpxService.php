<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class SpxService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 294;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://spx.co.th/api/v2/fleet_order/tracking/search?sls_tracking_number='.$trackNumber), $trackNumber);
    }

    public function trackNumberRules(): array
    {
        return [
            'TH[0-9]{11}' // TH014010005424
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dataJson = json_decode($data);

        if ($dataJson->message !== 'Success') {
            return false;
        }

        $result = new Parcel();
        $result->recipient = $dataJson->data->recipient_name;
        foreach ($dataJson->data->tracking_list as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint->timestamp);
            $result->statuses[] = new Status([
                'title' => $checkpoint->message,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}