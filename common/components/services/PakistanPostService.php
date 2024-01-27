<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class PakistanPostService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 461;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://ep.gov.pk/emtts/EPTrack_Live.aspx?ArticleIDz=' . $trackNumber), $trackNumber);
    }


    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{3}[0-9]{8}', //COD00582457
            '[A-Z]{2}[0-9]{9}[A-Z]{2}', //RH143473924TR
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $table = $dom->filterXPath('//table[@class="simple-table"]');

        if (!$table->count()) {
            return false;
        }

        $result = new Parcel();

        $date = '';
        $table->filterXPath('//tr')->each(function (Crawler $tr) use (&$result, &$date) {
            if ($tr->filterXPath('//div[@class="row-heading"]')->count()) {
                return false;
            }

            if ($tr->filterXPath('//div[@class="date-heading"]')->count()) {
                $date = $tr->filterXPath('//div[@class="date-heading"]')->text();
                return false;
            }

            $time = $tr->filterXPath('//td[@class="time"]//div')->text();
            $location = $tr->filterXPath('//td[3]//b')->text();
            $title = trim($tr->filterXPath('//td[4]')->text());

            $dateTime = Carbon::parse($date . ' ' . $time);

            $result->statuses[] = new Status([
                'title' => $title,
                'location' => $location,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute')
            ]);

            return true;
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'pk',
            'us',
            'kw',
            'sa',
        ];
    }

}