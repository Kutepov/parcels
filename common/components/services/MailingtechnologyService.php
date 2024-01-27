<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class MailingtechnologyService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 468;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'N[0-9]{11}', //N90001845024
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.mailingtechnology.com/tracking/?tn=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        if (!$dom->filterXPath('//div[@class="BodyBlock"]//table')->count()) {
            return false;
        }

        $result = new Parcel();

        $result->weightValue = $dom->filterXPath('//div[@class="BodyBlock"][1]//table//tr[4]//td')->text();
        $result->destinationCountry = $dom->filterXPath('//div[@class="BodyBlock"][1]//table//tr[5]//td')->text();

        $result->extraInfo = [
            $dom->filterXPath('//div[@class="BodyBlock"][1]//table//tr[2]//th')->text() => $dom->filterXPath('//div[@class="BodyBlock"][1]//table//tr[2]//td')->text(),
            $dom->filterXPath('//div[@class="BodyBlock"][1]//table//tr[3]//th')->text() => $dom->filterXPath('//div[@class="BodyBlock"][1]//table//tr[3]//td')->text()
        ];



        $dom->filterXPath('//div[@class="BodyBlock"][2]//table//tr')->each(function (Crawler $checkpoint) use (&$result) {
            if (!$checkpoint->filterXPath('//th')->count()) {
                $date = Carbon::parse($checkpoint->filterXPath('//td[1]')->text());
                $title = $checkpoint->filterXPath('//td[2]')->text() . ' | ' . $checkpoint->filterXPath('//td[3]')->text();

                $result->statuses[] = new Status([
                    'title' => $title,
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                ]);
            }
        });

        return $result;
    }

    public function restrictCountries()
    {
        return [
            'us',
            'uk',
            'it',
            'fn',
            'nl',
        ];
    }
}