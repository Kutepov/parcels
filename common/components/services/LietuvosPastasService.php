<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class LietuvosPastasService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 451;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.post.lt/siuntu-sekimas'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'search' => '1',
                'parcels' => $trackNumber,
            ]
        ]);
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' // RE240513803LT
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $table = $dom->filterXPath('//table[@class="table table-bordered table-shipment border-collapse"]');
        if (!$table->count()) {
            return false;
        }

        $result = new Parcel();

        $table->filterXPath('//tbody//tr')->each(function (Crawler $checkpoint) use (&$result) {
            $dateTime = Carbon::parse($checkpoint->filterXPath('//td[1]')->text());
            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//td[2]//p')->text(),
                'location' => $checkpoint->filterXPath('//td[3]')->text(),
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),

            ]);
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'lt',
            'uk',
            'us',
            'ie',
        ];
    }
}