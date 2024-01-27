<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class ABXExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 98;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '13149[0-9]{7}',
            'LZXL0000[0-9]{6}',
            'LZXL0001[0-9]{6}',
            'SHX[0-9]{8}AMY',
            'SHX[0-9]{8}BMY',
            'CNMYA000[0-9]{7}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.abxexpress.com.my/Home/TnT?trackingInfo=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $result = new Parcel();

        $dom->filter('#first-list')->each(static function (Crawler $node) use (&$result) {
            $status = trim($node->filter('.info')->first()->text());
            $dateTime = trim($node->filter('.time')->first()->text(null, true));
            $dateTime = str_replace('/', '.', $dateTime);

            $date = Carbon::parse($dateTime);

            if ($status !== 'No record available') {
                $result->statuses[] = new Status([
                    'title' => $status,
                    'location' => $node->filter('.title')->text(),
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                ]);
            }
        });

        if (count($result->statuses)) {
            return $result;
        }

        return false;
    }

    public function restrictCountries(): array
    {
        return ['my', 'sg', 'cn'];
    }
}