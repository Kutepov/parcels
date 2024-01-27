<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class BuffaloService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 139;
    private $url = 'http://buffaloex.com';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.buffaloex.com/track.html?order=' . $trackNumber), $trackNumber);

    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $ul = $dom->filterXPath('//ul[@class="el-timeline"]')->first();
        if (!$ul->count()) {
            return false;
        }

        $result = new Parcel();

        $ul->filterXPath('//li')->each(function (Crawler $checkpoint) use ($result) {
            $dateString = explode('(', $checkpoint->filterXPath('//div[@class="el-timeline-item__timestamp is-top"]')->text())[0];

            $date = Carbon::parse($dateString);

            $result->statuses[] = new Status([
                'title' => trim($checkpoint->filterXPath('//div[@class="el-card__body"]//p')->text()),
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
            'BUF[A-Z]{2}[0-9]{10}YQ'
        ];
    }
}