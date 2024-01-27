<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class KuehneService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
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
            '00340312[0-9]{12}' // 00340312891070057186
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://mykn.kuehne-nagel.com/public-tracking/shipments?query=' . $trackNumber), $trackNumber, [],
            function (ResponseInterface $response) use ($trackNumber) {
                $data = $response->getBody()->getContents();
                $dom = new Crawler($data);

                if ($dom->filterXPath('//li[@class="shipment-list__item--roadfreight"]')->count()) {
                    $link = $dom->filterXPath('//li[@class="shipment-list__item--roadfreight"]//a')->attr('href');
                    return $this->sendAsyncRequestWithProxy(new Request('GET', $link), $trackNumber);
                } else {
                    return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://mykn.kuehne-nagel.com/public-tracking/shipments?query=' . $trackNumber), $trackNumber);
                }
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $checkpointsNodes = $dom->filterXPath('//table[@class="table-readonly table-cargo-flow-road"]//tbody//tr');

        $result = new Parcel();
        if ($checkpointsNodes->count()) {
            $result->weightValue = $dom->filterXPath('//*[@id="shipmentContentPanel-totalWeight"]')->text();
            $checkpointsNodes->each(function (Crawler $checkpoint) use (&$result) {
                $dateString = $checkpoint->filterXPath('//td[@class="table-readonly__cell t-statusdatetime statusdatetime"]')->attr('data-title');
                [$date, $time] = explode(' | ', $dateString);
                [$day, $month, $year] = explode(' ', $date);

                $dateObj = Carbon::parse($day . '.' . self::MONTHS[$month] . '.' . $year . ' ' . $time);
                $result->statuses[] = new Status([
                    'title' => $checkpoint->filterXPath('//td[@class="table-readonly__cell t-statusname statusname"]')->text(),
                    'location' => $checkpoint->filterXPath('//td[@class="table-readonly__cell t-statuslocation statuslocation"]')->text(),
                    'date' => $dateObj->timestamp,
                    'dateVal' => $dateObj->toDateString(),
                    'timeVal' => $dateObj->toTimeString('minute'),
                ]);
            });
        }
        return (!empty($result->statuses)) ? $result : false;
    }
}