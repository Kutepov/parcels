<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class LithuaniaPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 170;
    private $url = 'https://www.post.lt/index.php/en/shipments-search';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}LT',
            'C[A-Z]{1}[0-9]{9}LT',
            'E[A-Z]{1}[0-9]{9}LT',
            'L[A-Z]{1}[0-9]{9}LT',
            'M[A-Z]{1}[0-9]{9}LT',
            'R[A-Z]{1}[0-9]{9}LT',
            'S[A-Z]{1}[0-9]{9}LT',
            'U[A-Z]{1}[0-9]{9}LT',
            'V[A-Z]{1}[0-9]{9}LT'
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
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.post.lt/index.php/en/shipments-search'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'search' => 1,
                'parcels' => $trackNumber
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());
        $checkpoints = $dom->filterXPath('//table[@class="table table-bordered table-shipment border-collapse"]//tbody//tr');

        if (!$checkpoints->count()) {
            return false;
        }

        $parcel = new Parcel();

        $checkpoints->each(function (Crawler $checkpoint) use ($parcel) {
            $dateTime = Carbon::parse($checkpoint->filterXPath('//td[1]')->text());

            $parcel->statuses[] = new Status([
                'title' => trim(str_replace("\n", '', $checkpoint->filterXPath('//td[2]')->text())),
                'location' => $checkpoint->filterXPath('//td[3]')->text(),
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute')
            ]);
        });

        return (!empty($parcel->statuses)) ? $parcel : false;
    }
}