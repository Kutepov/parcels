<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;

class BoynerService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

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
            '[A-Z]{3}[0-9]{12}-[0-9]{1}' // EKL101149464601-2
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://nerede.scotty.com.tr/kargom-nerede?tracking_code='.$trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = (new Dom())->loadStr($data);

        $result = new Parcel();

        foreach ($dom->find('#order_logs', 0)->find('tbody', 0)->find('tr') as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint->find('td', 2)->text);
            $result->statuses[] = new Status([
                'title' => html_entity_decode($checkpoint->find('td', 1)->text),
                'date' => $dateTime->timestamp,
                'location' => $checkpoint->find('td', 5)->text,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}