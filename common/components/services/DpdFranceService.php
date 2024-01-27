<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class DpdFranceService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 464;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://trace.dpd.fr/fr/trace/' . $trackNumber), $trackNumber);
    }


    public function trackNumberRules(): array
    {
        return [
            '2500[0-9]{11, 14}', //250068103890300 250077112906231159
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $table = $dom->filterXPath('//table[@id="tableTrace"]');
        if (!$table->count()) {
            return false;
        }

        $result = new Parcel();
        $result->weightValue = $dom->filterXPath('//div[@id="infos1"]//ul[@class="tableInfosAR"][3]//li[2]')->text();

        $table->filterXPath('//tr')->each(function (Crawler $checkpoint) use (&$result) {
            if (!$checkpoint->filterXPath('//td')->count()) {
                return false;
            }

            $date = str_replace('/', '-', $checkpoint->filterXPath('//td[1]')->text());
            $time = $checkpoint->filterXPath('//td[2]')->text();
            $dateTime = Carbon::parse($date . ' ' . $time);

            $result->statuses[] = new Status([
                'title' => trim($checkpoint->filterXPath('//td[3]')->text()),
                'location' => $checkpoint->filterXPath('//td[4]')->text(),
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
            'fr',
            'sw',
            'be',
            'de',
        ];
    }

}