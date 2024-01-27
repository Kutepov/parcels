<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class IntelComExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 214;
    private $statusList = [];


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://intelcom.ca/en/track-your-package/?tracking-id=' . $trackNumber), $trackNumber, [], function (ResponseInterface $response) use ($trackNumber) {
            $dom = new Dom();
            $dom->loadStr($response->getBody()->getContents());

            $statuses = $dom->find('.js-tracking-codes');
            $this->statusList = json_decode(str_replace('&quot;', '"',$statuses->text()), true);

            return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://intelcom.ca/cfworker/tracking/'.$trackNumber), $trackNumber);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        if ($data['data']['code'] === 'not_found') {
            return false;
        }

        $result = new Parcel();
        foreach ($data['data']['result']['status_list'] as $checkpoint) {
            $date = Carbon::parse(date('d.m.Y H:i:s', $checkpoint['timestamp']));

            $result->statuses[] = new Status([
                'title' => $this->statusList[$checkpoint['status']]['short_label_en'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return $result;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [];
    }
}