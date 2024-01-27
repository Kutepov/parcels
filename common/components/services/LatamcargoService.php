<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class LatamcargoService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 440;

    private const MONTHS = [
        'JAN' => 1,
        'FEB' => 2,
        'MAR' => 3,
        'APR' => 4,
        'MAY' => 5,
        'JUN' => 6,
        'JUL' => 7,
        'AUG' => 8,
        'SEP' => 9,
        'OCT' => 10,
        'NOV' => 11,
        'DEC' => 12,
    ];

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://mycargomanager.appslatam.com/etracking-web/publico/detalleGuia.do?prefix=' . substr($trackNumber, 0, 4) . '&number=' . substr($trackNumber, 4) . '&style=TA&ultimoFoco=&volv_ini=volv_ini&lang=EN'), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());
        $checkpoints = $dom->filterXPath('//table[@class="tabla-contenidos grilla-horizontal texto-negro"]//tr[not(@class="tabla-header") and not(@class="tabla-header-gris")]');


        if (!$checkpoints->count()) {
            return false;
        }

        $result = new Parcel();
        $result->departureAddress = $dom->filterXPath('//div[@id="margen-contenido"]//table//tr[2]//td[2]')->text();
        $result->destinationAddress = $dom->filterXPath('//div[@id="margen-contenido"]//table//tr[3]//td[2]')->text();
        $result->weightValue = $dom->filterXPath('//div[@id="margen-contenido"]//table//tr[3]//td[4]')->text();

        $checkpoints->each(function (Crawler $checkpoint) use (&$result) {
            $dateParts = explode(' ', htmlentities($checkpoint->filterXPath('//td[4]')->text(), null, 'utf-8'));
            if (count($dateParts) === 3) {
                $dateString = $dateParts[0] . '-' . self::MONTHS[$dateParts[1]] . '-' . date('Y') . $dateParts[2];
            } else {
                $dateString = implode(' ', $dateParts);
            }

            $date = Carbon::parse(str_replace('&nbsp;', ' ', $dateString));
            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//td[2]')->text(),
                'location' => $checkpoint->filterXPath('//td[3]')->text(),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]);
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{11}' //65676683795
        ];
    }

    public function restrictCountries()
    {
        return [
            'br',
            'cl',
            'us',
            'pe',
            'ar'
        ];
    }

}