<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;

class MbeService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{4}-[0-9]{1}-[0-9]{8}-[0-9]{2}' // IT0664-1-00025501-01
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.mbe.it/it/tracking'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'trackingCode' => $trackNumber
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = (new Dom())->loadStr($data);

        $result = new Parcel();
        foreach ($dom->find('.stato-ordine', 0)->find('tr') as $index => $checkpoint) {

            if ($index > 0) {

                $dateTime = Carbon::parse(str_replace('/', '.', $checkpoint->find('td', 0)->text));

                $result->statuses[] = new Status([
                    'title' => $checkpoint->find('td', 1)->text,
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                ]);
            }
        }


        return (!empty($result->statuses)) ? $result : false;
    }
}