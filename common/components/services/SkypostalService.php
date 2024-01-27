<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class SkypostalService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 455;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://tracking.skypostal.com/single-tracking.aspx?trck_number=' . $trackNumber), $trackNumber);
    }


    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}', //NX209289700BR
            '[0-9]{10}', //1931047808
            'ESUS[0-9]{8}', //1931047808 ESUS49554587
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $table = $dom->filterXPath('//table[@class="table table-bordered table-tracking"]');

        if (!$table->count()) {
            return false;
        }

        $result = new Parcel();

        $table->filterXPath('//tr')->each(function (Crawler $checkpoint) use (&$result) {
            [$month, $day, $yearTime] = explode('/', $checkpoint->filterXPath('//td[5]')->text());
            $date = Carbon::parse($day . '-' . $month . '-' . $yearTime);

            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//td[4]')->text(),
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
            'co',
            'us',
        ];
    }

}