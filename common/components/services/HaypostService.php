<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class HaypostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 150;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}AM',
            'C[A-Z]{1}[0-9]{9}AM',
            'E[A-Z]{1}[0-9]{9}AM',
            'L[A-Z]{1}[0-9]{9}AM',
            'R[A-Z]{1}[0-9]{9}AM',
            'S[A-Z]{1}[0-9]{9}AM',
            'V[A-Z]{1}[0-9]{9}AM'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}\d{9}[A-Z]{2}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://api.haypost.am/trackingNumber/?trackingNumber='.$trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);
        if ($data['error'] === true) {
            return false;
        }

        $statuses = [];
        foreach ($data['data']['result'] as $checkpoint) {
            $dateTime = Carbon::parse(str_replace('.', '-', $checkpoint['local_Date']));

            $statuses[] = new Status([
                'title' => $checkpoint['event'],
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
                'location' => $checkpoint['country'] . ' ' . $checkpoint['location'],
            ]);

        }

        return new Parcel([
            'statuses' => $statuses
        ]);
    }
}