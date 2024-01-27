<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use PHPHtmlParser\Dom;

class DellinService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 421;

    private const MONTHS = [
        'янв' => 1,
        'фев' => 2,
        'мар' => 3,
        'апр' => 4,
        'май' => 5,
        'июн' => 6,
        'июл' => 7,
        'авг' => 8,
        'сен' => 9,
        'окт' => 10,
        'ноя' => 11,
        'дек' => 12,
    ];

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        }
        catch (\Exception $exception) {}
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{13}' // 2101001040339
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.dellin.ru/tracker/orders/'.$trackNumber.'/'), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = (new Dom())->loadStr($data);

        $result = new Parcel();
        $year = $this->getYear($dom->find('.ordered-at-to-date')->text());

        foreach ($dom->find('.history')->find('li') as $checkpoint) {
            $date = $checkpoint->find('.date')->text();
            if (!$date) {
                return false;
            }

            [$day, $month] = explode(' ', $date);

            $dateTime = Carbon::parse($day.'-'.self::MONTHS[$month].'-'.$year);
            $result->statuses[] = new Status([
                'title' => $checkpoint->find('.message')->text(),
                'date' => $dateTime->timestamp,
                'location' => $checkpoint->find('.city_nowrap')->count() ? $checkpoint->find('.city_nowrap')->text() : null,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    private function getYear($string)
    {
        [,,$year] = explode('.', $string);
        return $year;
    }

    public function restrictCountries(): array
    {
        return ['ru', 'ua'];
    }
}