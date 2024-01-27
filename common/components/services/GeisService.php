<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class GeisService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 456;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.geis.pl/pl/detail-of-cargo?packNumber=' . $trackNumber), $trackNumber);
    }


    public function trackNumberRules(): array
    {
        return [
            '[0-9]{13}', //4715000100406
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $table = $dom->filterXPath('//div[@class="grid-item collapsable ca-default-expanded ca-expanded"]//table[@class="table-small-side-padding table-tracking"]');

        if (!$table->count()) {
            return false;
        }

        $result = new Parcel();

        $table->filterXPath('//tbody//tr')->each(function (Crawler $checkpoint) use (&$result) {
            [$day, $month, $year] = explode('. ', $checkpoint->filterXPath('//td[1]')->text());
            $time = $checkpoint->filterXPath('//td[2]')->text();
            $date = Carbon::parse($day . '-' . $month . '-' . $year . ' ' . $time);

            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//td[5]')->text(),
                'location' => $checkpoint->filterXPath('//td[4]')->text() . ' ' . $checkpoint->filterXPath('//td[3]')->text(),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);

        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'pl',
            'de',
        ];
    }

}