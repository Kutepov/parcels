<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class ApcPli extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
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
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' // UM145424121US
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://api2.apc-pli.com/api/tracking/'.$trackNumber), $trackNumber,
            [
                RequestOptions::HEADERS => [
                    'authorization' => 'Basic MTIxMjo1MkpkWWVjXiYw',

                ],
            ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dataJson = json_decode($data);


        $result = new Parcel();
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