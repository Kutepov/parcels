<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class ApcPliService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 287;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.apc-pli.com/tracking/apirequest.php?id=' . $trackNumber), $trackNumber);
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' // UM145424121US
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dataJson = json_decode($data);

        $result = new Parcel([
            'extraInfo' => [
                'Service' => $dataJson->serviceName
            ]
        ]);

        [, $result->destinationCountry, $result->destinationCountryCode] = explode(' ', $dataJson->shipToAddress);

        foreach ($dataJson->events as $checkpoint) {
            [$date, $time] = explode(' ', $checkpoint->date);
            [$month, $day, $year] = explode('.', $date);
            $dateTime = Carbon::parse($day . '-' . $month . '-' . $year . ' ' . $time);
            $result->statuses[] = new Status([
                'title' => $checkpoint->description,
                'location' => $checkpoint->location,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}