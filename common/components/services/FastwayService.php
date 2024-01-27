<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use yii\helpers\Json;

class FastwayService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 299;

    private $key = '7cd60d5ed6b256d1cb559848f93e2a7c';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://api.fastway.org/latest/tracktrace/detail/' . $trackNumber), $trackNumber, [
            RequestOptions::QUERY => [
                'api_key' => $this->key
            ]
        ]);
    }

    public function trackNumberRules(): array
    {
        return [
            'WY{2}[0-9]{10}' // WY0012062237
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = Json::decode($response->getBody()->getContents());

        $result = new Parcel();

        foreach ($data['result']['Scans'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['RealDateTime']);

            $result->statuses[] = new Status([
                'title' => $checkpoint['Description'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $checkpoint['Name']
            ]);
        }

        return $result;
    }
}