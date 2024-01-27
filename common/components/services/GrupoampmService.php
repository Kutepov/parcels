<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class GrupoampmService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 298;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://www.grupoampm.com/rastreador/?tracking-id=' . $trackNumber), $trackNumber);
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{12}' // 434992440017
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $table = $dom->filterXPath('//table[@id="grvMovimientos"]//tbody')->first();

        if (!$table->count()) {
            return false;
        }

        $result = new Parcel();

        $departureAddress = $dom->filterXPath('//div[@class="wrapper-bloque-infoCab-datos"][1]')->text();
        $departureAddress = preg_replace('/^([ ]+)|([ ]){2,}/m', '$2', $departureAddress);
        $destinationAddress = $dom->filterXPath('//div[@class="wrapper-bloque-infoCab-datos"][2]')->text();
        $destinationAddress = preg_replace('/^([ ]+)|([ ]){2,}/m', '$2', $destinationAddress);

        $result->departureAddress = trim(str_replace("\r\n", '', $departureAddress));
        $result->destinationAddress = trim(str_replace("\r\n", '', $destinationAddress));

        $table->filterXPath('//tr')->each(function (Crawler $checkpoint) use ($result) {
            if ($checkpoint->filterXPath('//th')->count()) {
                return;
            }
            $dateTime = Carbon::parse($checkpoint->filterXPath('//td[1]')->text());
            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//td[3]')->text(),
                'location' => $checkpoint->filterXPath('//td[4]')->text(),
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
            'mx'
        ];
    }
}