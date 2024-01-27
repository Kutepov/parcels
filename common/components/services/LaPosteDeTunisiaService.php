<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;

class LaPosteDeTunisiaService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 95;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://www.rapidposte.poste.tn/an/Item_Events.asp'), $trackNumber, [
            RequestOptions::QUERY => [
                'ItemId' => $trackNumber,
                'submit22.x' => '0',
                'submit22.y' => '0'
            ],
            RequestOptions::HEADERS => [
                'Referer' => 'http://www.rapidposte.poste.tn/an/index.html'
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        $checkpoints = $dom->filterXPath('//tr[contains(@class, "tabl")]');
        if (!$checkpoints->count()) {
            return false;
        }

        $result = new Parcel();
        $checkpoints->each(function (Crawler $checkpoint) use (&$result) {
            if ($checkpoint->filterXPath('//td')->count() === 2) {
                return;
            }
            $dateTime = Carbon::parse(str_replace('/', '-', $checkpoint->filterXPath('//td[1]')->text()));

            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//td[4]')->text(),
                'location' => $checkpoint->filterXPath('//td[2]')->text() . ' ' . $checkpoint->filterXPath('//td[3]')->text(),
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}TN',
            'C[A-Z]{1}[0-9]{9}TN',
            'E[A-Z]{1}[0-9]{9}TN',
            'L[A-Z]{1}[0-9]{9}TN',
            'R[A-Z]{1}[0-9]{9}TN',
            'S[A-Z]{1}[0-9]{9}TN',
            'U[A-Z]{1}[0-9]{9}TN',
            'V[A-Z]{1}[0-9]{9}TN'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

    public function restrictCountries()
    {
        return [
            'tn',
            'fr',
            'ca',
            'us',
        ];
    }
}