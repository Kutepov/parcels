<?php

namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class EmiratesPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 91;

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        } catch (\Exception $exception) {
        }
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www-new.emiratespost.ae/services/tracking/api/Tracking/trackAwb?awbNumber=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);
        $checkpoints = $data[0]['events'];
        $statuses = [];

        if (count($checkpoints) <= 1) {
            return false;
        }
        foreach ($checkpoints as $item) {
            $date = Carbon::parse(str_replace('/', '-', $item['timeStamp']));

            $statuses[] = new Status([
                'title' => $item['status']['descriptionEn'],
                'location' => $item['locationEn'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        $result = new Parcel();
        $result->sender = $data[0]['sender']['name'] . ' ' . $data[0]['sender']['contactNumber'];
        $result->recipient = $data[0]['receiver']['name'] . ' ' . $data[0]['receiver']['contactNumber'];
        $result->statuses = $statuses;

        return (!empty($result->statuses)) ? $result : false;
    }


    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}CU',
            'C[A-Z]{1}[0-9]{9}CU',
            'E[A-Z]{1}[0-9]{9}CU',
            'L[A-Z]{1}[0-9]{9}CU',
            'R[A-Z]{1}[0-9]{9}CU',
            'S[A-Z]{1}[0-9]{9}CU',
            'V[A-Z]{1}[0-9]{9}CU',
            'CU{1}[0-9]{9}RT',
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }
}