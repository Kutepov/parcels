<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class ZoomenviosService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 442;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://zoom.red/tracking-de-envios-personas/?nro-guia=' . $trackNumber . '&tipo-consulta=1'), $trackNumber);
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{10}' // 1219240336
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        if (!$dom->filterXPath('//table[@class="zappi-trk-resumen"]')->count()) {
            return false;
        }

        $result = new Parcel();

        $result->departureAddress = $dom->filterXPath('//table[@class="zappi-trk-resumen"]//tr[6]//td[2]')->text();
        $result->destinationAddress = $dom->filterXPath('//table[@class="zappi-trk-resumen"]//tr[7]//td[2]')->text();;

        $dom->filterXPath('//div[@class="zappi-trk-estados-tabla"]//div[@class="tabla-wrapper"]//tr')->each(function (Crawler $tr) use (&$result) {
            if (!$tr->filterXPath('//td')->count()) {
                return;
            }
            $date = $tr->filterXPath('//td[2]')->text();
            $time = $tr->filterXPath('//td[3]')->text();
            $dateTime = Carbon::parse(str_replace('/', '-', $date) . ' ' . $time);

            $result->statuses[] = new Status([
                'title' => $tr->filterXPath('//td[4]')->text(),
                'location' => $tr->filterXPath('//td[5]')->text(),
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
            've',
            'co',
            'us',
        ];
    }
}