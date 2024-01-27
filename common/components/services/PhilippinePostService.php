<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class PhilippinePostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    private $url = 'https://phlpost.gov.ph/checkpostoffice.php';
    public $id = 81;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}PH',
            'C[A-Z]{1}[0-9]{9}PH',
            'D[A-Z]{1}[0-9]{9}PH',
            'E[A-Z]{1}[0-9]{9}PH',
            'L[A-Z]{1}[0-9]{9}PH',
            'R[A-Z]{1}[0-9]{9}PH',
            'S[A-Z]{1}[0-9]{9}PH',
            'U[A-Z]{1}[0-9]{9}PH',
            'V[A-Z]{1}[0-9]{9}PH',
            'CD[A-Z]{1}[0-9]{9}ZZ',
            'RD[A-Z]{1}[0-9]{9}ZZ',
            'RE[A-Z]{1}[0-9]{9}ZZ'
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
        return $this->sendAsyncRequestWithProxy(new Request('POST', $this->url), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'tracking' => $trackNumber,
            ],
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        if (!$dom->filterXPath('//table[@class="stripe"]')->count()) {
            return false;
        }

        $result = new Parcel();

        $dom->filterXPath('//tbody//tr')->each(function (Crawler $checkpoint) use (&$result) {
            $date = Carbon::parse($checkpoint->filterXPath('//td[2]')->text());
            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//td[1]')->text(),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]);
        });

        return $result;
    }
}