<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class Minutos99 extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
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
            '[0-9]{16}' // 1123041237590678
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://tracking-service-qndxoltwga-uc.a.run.app/consult'), $trackNumber,
            [
                RequestOptions::HEADERS => [
                    'content-type' => 'application/json;charset=UTF-8',

                ],
                RequestOptions::BODY => '{"orderCounter":"'.$trackNumber.'"}'
            ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dataJson = json_decode($data);

        $result = new Parcel();

        foreach ($dataJson->order->response->message[0]->events as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint->date.' '.$checkpoint->time);
            $result->statuses[] = new Status([
                'title' => $checkpoint->comment ?: 'Hemos recibido tu orden de envío',
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}