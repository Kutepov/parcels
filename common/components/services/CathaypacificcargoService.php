<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class CathaypacificcargoService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 439;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://node:3000'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'requestUrl' => 'https://www.cathaypacificcargo.com/ManageYourShipment/TrackYourShipment/tabid/108/SingleAWBNo/'.$trackNumber.'-/language/en-US/Default.aspx'
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $checkpoints = $dom->filterXPath('//div[@id="ShipmentHistory-Content"]/div');

        if (!$checkpoints->count()) {
            return false;
        }

        $result = new Parcel();
        $result->weight = $dom->filterXPath('//span[@id="FreightStatus-QDWeight"]')->text() * 1000;

        $checkpoints->each(function (Crawler $checkpoint) use (&$result) {
            $date = Carbon::parse($checkpoint->filterXPath('//span[@class="shipment-status_status_detail_datetime"]')->text());
            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//span[@class="shipment-status_status"]')->text(),
                'location' => $checkpoint->filterXPath('//span[@class="shipment-status_status_detail"][1]')->text(),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]);
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{3}-[0-9]{8}' //160-27509731
        ];
    }

    public function restrictCountries()
    {
        return [
            'ch',
            'vn',
            'us',
            'jp',
            'cl'
        ];
    }

}