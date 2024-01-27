<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class GeorgianPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 90;
    private $url = 'http://gpost.ge';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}GE',
            'C[A-Z]{1}[0-9]{9}GE',
            'E[A-Z]{1}[0-9]{9}GE',
            'H[A-Z]{1}[0-9]{9}GE',
            'L[A-Z]{1}[0-9]{9}GE',
            'R[A-Z]{1}[0-9]{9}GE',
            'S[A-Z]{1}[0-9]{9}BN',
            'U[A-Z]{1}[0-9]{9}BN',
            'V[A-Z]{1}[0-9]{9}BN'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://gpost.ge/tracking/track-code?trackingCode=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        if (!$dom->filterXPath('//div[@class="com-packtracks"]')->count()) {
            return false;
        }

        $result = new Parcel();

        $dom->filterXPath('//div[@class="com-packtrack-pos"]')->each(function (Crawler $checkpoint) use (&$result) {
            $date = Carbon::parse(str_replace('.', '-', $checkpoint->filterXPath('//div[@class="com-packtrack-date"]')->text()));

            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//div[@class="com-packtrack-status"]')->text(),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]);
        });

        return empty($result->statuses) ? false : $result;
    }
}