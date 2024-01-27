<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class OrangeconnexService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, BatchTrackInterface
{
    public $id = 465;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://azure-cn.orangeconnex.com/oc/capricorn-website/website/v1/tracking/traces'), $trackNumber, [
            RequestOptions::JSON => [
                'language' => 'zh-CN',
                'trackingnumbers' => (array)$trackNumber,
            ]
        ]);
    }

    public function trackNumberRules(): array
    {
        return [
            'ES10015[0-9]{18}C0N', //ES10015499200600001010001C0N
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);

        if (!count($json['result']['waybills'])) {
            return false;
        }

        $result = new Parcel();

        foreach ($json['result']['waybills'] as $trackItem) {
            if ($trackItem['trackingNumber'] === $trackNumber) {
                foreach ($trackItem['traces'] as $checkpoint) {
                    $dateTime = Carbon::parse($checkpoint['oprTime']);

                    $location = '';
                    $location .= $checkpoint['oprCountry'] ?? '';
                    if ($location !== '') {
                        $location .= ' ';
                    }
                    $location = $checkpoint['oprCity'] ?? '';

                    $result->statuses[] = new Status([
                        'title' => $checkpoint['eventDescCn'],
                        'location' => $location,
                        'date' => $dateTime->timestamp,
                        'dateVal' => $dateTime->toDateString(),
                        'timeVal' => $dateTime->toTimeString('minute')
                    ]);
                }
            }
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'us',
            'cn',
            'de',
            'uk',
            'au',
        ];
    }

    public function batchTrackMaxCount()
    {
        return 20;
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }
}