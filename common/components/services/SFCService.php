<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class SFCService extends BaseService implements ValidateTrackNumberInterface, ExcludeTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 111;
    private $url = 'http://www.sfcservice.com';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.sfcservice.com/track/track/get-track-for-web'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'tracknumber' => $trackNumber,
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $request = json_decode($response->getBody()->getContents(), true);

        if (!empty($request['trackingList'])) {
            $statuses = [];

            foreach ($request['trackingList'] as $item) {

                $date = Carbon::parse(str_replace('/', '.', $item['date']));
                $statuses[] = new Status([
                    'title' => trim($item['statu']),
                    'location' => trim($item['location']),
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                ]);
            }

            return new Parcel([
                'statuses' => $statuses
            ]);
        }

        return false;
    }

    public function excludedTrackNumberRules(): array
    {
        return [
            'TB(A|C|M)\d{12}'
        ];
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z0-9]{3}[0-9]{12}',
            'SFC\d[A-Z]{2}\d{13}'
        ];
    }
}