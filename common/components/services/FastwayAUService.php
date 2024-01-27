<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class FastwayAUService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 123;
    private $url = 'https://www.fastway.com.au';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.aramex.com.au/track-api-v6/?callback&LabelNo=' . $trackNumber . '&dataFormat=json'), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $request = json_decode($response->getBody()->getContents(), true);

        if (!empty($request['result'])) {

            foreach ($request['result']['Scans'] as $item) {
                $dateTime = Carbon::parse($item['RealDateTime']);
                $statuses[] = new Status([
                    'title' => $item['Description'],
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                    'location' => $item['Name']
                ]);
            }

            return new Parcel([
                'statuses' => $statuses
            ]);
        }

        return false;
    }

    public function trackNumberRules(): array
    {
        return [
            'K71083[0-9]{4}',
            'BN[0-9]{10}'
        ];
    }
}