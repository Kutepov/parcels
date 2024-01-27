<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class CoordinadoraService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    private const MONTHS = [
        'Jan' => 1,
        'Feb' => 2,
        'Mar' => 3,
        'Apr' => 4,
        'May' => 5,
        'Jun' => 6,
        'Jul' => 7,
        'Aug' => 8,
        'Sep' => 9,
        'Oct' => 10,
        'Nov' => 11,
        'Dec' => 12,
    ];

    public $id = 454;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.coordinadora.com/portafolio-de-servicios/servicios-en-linea/rastrear-guias/#rastreo'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'coor_guia' => $trackNumber,
                'coor_guia_home' => 'true'
            ]
        ]);
    }


    public function trackNumberRules(): array
    {
        return [
            '[0-9]{11}', //79340919976
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        if (!$dom->filterXPath('//div[@class="estado_guia"]')->count()) {
            return false;
        }

        $result = new Parcel([
            'destinationAddress' => $dom->filterXPath('//div[@class="guia-data dot1"]//table//tr[2]//td[2]')->first()->text(),
            'departureAddress' => $dom->filterXPath('//div[@class="guia-data dot1"]//table//tr[1]//td[2]')->first()->text(),
        ]);

        $dom->filterXPath('//h4[contains(text(), "Detalles y fechas")]/following::div[1]//div[@class="estado_guia"]')->each(function (Crawler $checkpoint) use (&$result) {
            $day = trim($checkpoint->filterXPath('//div[@class="dateNumbers"]')->text());
            $month = self::MONTHS[$checkpoint->filterXPath('//span[@class="dateCharsMonth"]')->text()];
            $year = trim($checkpoint->filterXPath('//span[@class="dateCharsYear"]')->text());
            $time = $checkpoint->filterXPath('//div[@class="dateCharsTime"]')->text();
            $date = Carbon::parse($day . '-' . $month . '-' . $year . ' ' . $time);

            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//div[@class="left desc-estado"]//div')->text(),
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