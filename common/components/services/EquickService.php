<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use stdClass;

class EquickService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, BatchTrackInterface
{
    public $id = 121;
    private $url = 'http://www.equick.cn';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'EQK[A-Z]{2}[0-9]{10}YQ'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://123.56.22.107:8080/api/traceQuery'), $trackNumber, [
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json'
            ],
            RequestOptions::JSON => [
                'txtDanHao' => implode("\n", (array) $trackNumber)
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $items = json_decode($response->getBody()->getContents(), true);

        $result = new Parcel();

        foreach ($items['data'][$trackNumber] as $checkpoint) {
            $date = Carbon::parse($checkpoint['traceDateTime']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['traceContent'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $checkpoint['traceCountry'],
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 50;
    }

}