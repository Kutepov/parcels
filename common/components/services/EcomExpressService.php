<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class EcomExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 232;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://ecomexpress.in/tracking/?awb_field=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());
        $result = new Parcel();

        if (!$dom->filterXPath('//div[@class="circles-container"]//div[@class="circle circle1 active"]')->count()) {
            return false;
        }

        $dom->filterXPath('//div[@class="circles-container"]//div[@class="circle circle1 active"]//div[@class="tracking-status-content-right"]')->each(static function (Crawler $node) use (&$result) {
                $title = $node->filterXPath('//p[1]')->text();
                $location = $node->filterXPath('//p[2]//span[1]')->text();
                $date = $node->filterXPath('//p[2]//span[2]')->text();
                $date = str_replace('Date: ', '', $date);
                $date = str_replace(' hrs,', '', $date);
                $date = str_replace(',', '', $date);

                $dateTime = Carbon::parse(($date));

                $result->statuses[] = new Status([
                    'title' => $title,
                    'location' => $location,
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute')
                ]);
        });

        return (!empty($result->statuses)) ? $result : false;
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
        return ['in'];
    }
}