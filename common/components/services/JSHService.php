<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class JSHService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 144;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://www.galaxy-ex.com:8082/trackIndex.htm'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'documentCode' => $trackNumber
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = new Crawler($data);
        if (!$dom->filterXPath('//table[@style="table-layout:fixed;display: inline-table;"]')->count()) {
            return false;
        }

        $result = new Parcel();
        $dom->filterXPath('//table[@style="table-layout:fixed;display: inline-table;"]//tr')->each(function (Crawler $checkpoint) use (&$result) {
            $dateTime = Carbon::parse($checkpoint->filterXPath('//td[1]')->text());
            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//td[3]')->text(),
                'location' => $checkpoint->filterXPath('//td[2]')->text(),
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute')
            ]);
        });

        $result->destinationCountryCode = $dom->filterXPath('//li[@class="div_li1"]')->text();
        $result->recipient = $dom->filterXPath('//li[@class="div_li3"]//span')->text();

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            'JSH[A-Z]{2}[0-9]{10}YQ'
        ];
    }
}