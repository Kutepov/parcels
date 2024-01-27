<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class DhlService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    private const MONTHS = [
        'Январь' => 1,
        'Февраль' => 2,
        'Март' => 3,
        'Апрель' => 4,
        'Май' => 5,
        'Июнь' => 6,
        'Июль' => 7,
        'Август' => 8,
        'Сентябрь' => 9,
        'Октябрь' => 10,
        'Ноябрь' => 11,
        'Декабрь' => 12,
    ];

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{3}[0-9]{18}' // JJD000390007551085674
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://node:3000/'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'requestUrl' => 'https://www.dhl.com/ua-ru/home/tracking/tracking-parcel.html?submit=1&tracking-id=' . $trackNumber,
                'method' => 'GET',
                'waitForSelector' => '//*[@class="c-tracking-result--detail l-grid l-grid--w-100pc-s l-grid--p-2u-m "]',
            ],
            RequestOptions::HEADERS => [
                'authority' => 'www.dhl.com',
                'method' => 'GET',
                'path' => '/ua-ru/home/tracking/tracking-parcel.html?submit=1&tracking-id=' . $trackNumber,
                'scheme' => 'https',
                'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'accept-encoding' => 'gzip, deflate, br',
                'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'cache-control' => 'no-cache',
                'pragma' => 'no-cache',
                'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="97", "Chromium";v="97"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'sec-fetch-dest' => 'document',
                'sec-fetch-mode' => 'navigate',
                'sec-fetch-site' => 'same-origin',
                'sec-fetch-user' => '?1',
                'upgrade-insecure-requests' => '1',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.99 Safari/537.36',
            ],
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());
        $checkpoints = $dom->filterXPath('//div[@class="c-tracking-result--checkpoint l-grid l-grid--w-100pc-s l-grid--left-s"]');

        if (!$checkpoints->count()) {
            return false;
        }

        $result = new Parcel();

        $checkpoints->each(function (Crawler $checkpoint) use (&$result) {
            $dateString = $checkpoint->filterXPath('//h4[@class="c-tracking-result--checkpoint--date  l-grid--w-100pc-s"]')->text();
            [$month, $dayYear] = explode(', ', $dateString);
            [$day, $year] = explode(' ', $dayYear);
            $checkpoint->filterXPath('//span[@class="c-tracking-result--checkpoint-location-status l-grid--w-100pc-s"]')->each(function (Crawler $point) use (&$result, $month, $day, $year) {
                [$time, $title] = explode('|',trim($point->text()));
                $time = explode(' ', $time)[0];
                $date = Carbon::parse($day . '-' . self::MONTHS[$month] . '-' . $year . ' ' . $time);

                $result->statuses[] = new Status([
                    'title' => $title,
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                ]);
            });
        });

        return (!empty($result->statuses)) ? $result : false;
    }
}