<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class DpxLogisticsService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, BatchTrackInterface
{
    public $id = 459;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.dpxlogistics.com/?page_id=527'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'checking_id' => is_array($trackNumber) ? implode("\r\n", $trackNumber) : $trackNumber,
            ]
        ]);
    }


    public function trackNumberRules(): array
    {
        return [
            'E36700[0-9]{6}', //E36700155869
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $tracks = $dom->filterXPath('//div[@class="tracking_item"]');

        $result = new Parcel();

        $tracks->each(function (Crawler $trackInfo) use (&$result, $trackNumber) {

            if ($trackInfo->filterXPath('//span[@class="sending_data"]')->text() === $trackNumber && $trackInfo->filterXPath('//table[@class="trackingInfo"]')->count()) {
                $trackInfo->filterXPath('//table[@class="trackingInfo"]//tr')->each(function (Crawler $checkpoint) use (&$result) {
                    if ($checkpoint->filterXPath('//td')->count()) {
                        $date = Carbon::parse($checkpoint->filterXPath('//td[1]')->text());
                        $result->statuses[] = new Status([
                            'title' => $checkpoint->filterXPath('//td[3]')->text(),
                            'location' => $checkpoint->filterXPath('//td[2]')->text(),
                            'date' => $date->timestamp,
                            'dateVal' => $date->toDateString(),
                            'timeVal' => $date->toTimeString('minute')
                        ]);
                    }
                });
            }

        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'th',
        ];
    }

    public function batchTrackMaxCount()
    {
        return 20;
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }
}