<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use stdClass;

class Parcel2GOService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 154;
    private $url = 'https://www.parcel2go.com';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://tracking-edge.serverless.p2g.systems/PARCEL2GO.UK.LIVE/tracking/' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        if ($data['success'] === false) {
            return false;
        }

        $statuses = [];
        foreach ($data['result']['parcels'][$trackNumber]['events'] as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint['timestamp']);

            $statuses[] = new Status([
                'title' => $checkpoint['detail'],
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return new Parcel([
            'statuses' => $statuses
        ]);

    }

    public function trackNumberRules(): array
    {
        return [
            'P2G[0-9]{8}'
        ];
    }
}