<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class ExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $mainData;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->request($trackNumber);
    }

    public function track($trackNumber)
    {
        return $this->request($trackNumber)->wait();
    }

    public function request($trackNumber)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://8express.ru/ru/tracking?inv='.$trackNumber), $trackNumber);
    }


    public function parseResponse($response, $trackNumber)
    {

        $data = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($data);

        foreach ($dom->find('.tmore-days')->find('.tmore-day') as $days) {
            $date = $days->find('.tmore-day-date', 0)->text;

            foreach ($days->find('.tmore-day-items', 0)->find('.tmore-day-item') as $checkpoint) {

                $time = $checkpoint->find('.tmore-day-time', 0)->text;
                $dateTime = Carbon::parse($date.' '.$time);

                $statuses[] = new Status([
                    'title' => $checkpoint->find('.tmore-day-info')->text,
                    'location' => $checkpoint->find('.tmore-day-city')->text,
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute')
                ]);
            }
        }

        return isset($statuses) ? new Parcel(['statuses' => $statuses]) : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{3}[0-9]{10}' // MSK0007308445
        ];
    }
}