<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class MagicTransService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 434;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://magic-trans.ru/otsledit-gruz/'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'act-no' => $trackNumber
            ],
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = new Crawler($data);

        $statusDate = $dom->filterXPath('//div[@class="tracing-info-status"][3]')->text();

        if (!$statusDate) {
            return false;
        }

        $result = new Parcel();
        $date = Carbon::parse($statusDate);
        $result->statuses[] = (new Status([
            'date' => $date->timestamp,
            'dateVal' => $date->toDateString(),
            'timeVal' => $date->toTimeString('minute'),
            'title' => 'В пути',
        ]));

        $statusDate = $dom->filterXPath('//div[@class="tracing-info-status"][5]')->text();

        if ($statusDate) {
            $date = Carbon::parse($statusDate);
            $result->statuses[] = (new Status([
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'title' => 'Ориентировочная дата доставки',
            ]));
        }

        return $result;
    }

    public function trackNumberRules(): array
    {
        return [
            'TR[0-9]{9}MT' //TR008410638MT
        ];
    }

    public function restrictCountries()
    {
        return [
            'ru', 'uk'
        ];
    }
}