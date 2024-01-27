<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;

class PaczkaWRuchuService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 231;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://nadaj.orlenpaczka.pl/parcel/api-status?id=' . $trackNumber . '&jsonp=callback&_=' . time() . rand(100, 999)), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $data = preg_replace('#^callback\((.*?)\);#si', '$1', $data);
        $data = json_decode($data, true);

        foreach ($data['history'] as $checkpoint) {
            $date = Carbon::parse(str_replace('-', '.', $checkpoint['date']));

            $statuses[] = new Status([
                'title' => $checkpoint['label'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return isset($statuses) ? new Parcel(['statuses' => $statuses]) : false;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackNumberRules(): array
    {
        return [];
    }

    public function restrictCountries()
    {
        return ['pl'];
    }
}