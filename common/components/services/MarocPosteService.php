<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class MarocPosteService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 458;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://bam-tracking.barid.ma/Tracking/Search?trackingCode=' . $trackNumber), $trackNumber);
    }


    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}', //CC018056552ES
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);

        $dom = new Crawler($json['Html']);

        $checkpoints = $dom->filterXPath('//ul[@class="timeline"]//li');

        if(!$checkpoints->count()) {
            return false;
        }

        $result = new Parcel();

        if ($dom->filterXPath('//span[@class="b-subtitle lblWeight"]')->count()) {
            $result->weightValue = $dom->filterXPath('//span[@class="b-subtitle lblWeight"]')->text();
        }

        $checkpoints->each(function (Crawler $checkpoint) use (&$result) {
            $date = str_replace('/', '-', $checkpoint->filterXPath('//div[@class="container_date"]')->text());
            $time = $checkpoint->filterXPath('//div[@class="container_time"]')->text();

            $titleLocation = trim($checkpoint->filterXPath('//div[@class="mt-3 mb-5"]')->html());
            if (count(explode(' <b>', $titleLocation)) === 1) {
                $title = $titleLocation;
                $location = null;
            } else {
                [$title, $location] = explode(' <b>', $titleLocation);
            }
            $date = Carbon::parse($date . $time);
            $result->statuses[] = new Status([
                'title' => $title,
                'location' => substr($location, 0, -4),
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
            'ma',
            'fr',
            'us',
        ];
    }
}