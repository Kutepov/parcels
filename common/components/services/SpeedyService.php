<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use PHPHtmlParser\Dom;

class SpeedyService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 424;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{11}' // 51002693425
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.speedy.bg/en/track-shipment?shipmentNumber=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = (new Dom())->loadStr($data);

        if (!$dom->find('.shipment-table')->count()) {
            return false;
        }

        $result = new Parcel();
        foreach ($dom->find('.shipment-table')->find('tbody')->find('tr') as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint->find('td', 0)->text());
            $result->statuses[] = new Status([
                'title' => $checkpoint->find('td', 1)->text(),
                'date' => $dateTime->timestamp,
                'location' => $checkpoint->find('td', 2)->text(),
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'bg'
        ];
    }
}