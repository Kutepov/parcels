<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class NaqelService extends BaseService implements ValidateTrackNumberInterface, CountryRestrictionInterface, AsyncTrackingInterface
{
    public $id = 130;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{8}',
            '20[0-9]{6}',
            '68[0-9]{6}',
            '69[0-9]{6}',
            '70[0-9]{6}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://new.naqelksa.com/en/tracking/'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            $dom = new Crawler($response->getBody()->getContents());
            $csrf = $dom->filterXPath('//input[@name="csrfmiddlewaretoken"]')->attr('value');
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://new.naqelksa.com/en/tracking/'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::FORM_PARAMS => [
                    'csrfmiddlewaretoken' => $csrf,
                    'waybills' => $trackNumber,
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        if (!$dom->filterXPath('//div[@class="card-body"]')->count()) {
            return false;
        }

        $result = new Parcel();

        $dom->filterXPath('//div[@class="card-body"]/div[@class="row"]')->each(function (Crawler $block) use (&$result) {
            $date = $block->filterXPath('//div[@class="col-md-12 rounded-pill border-dangerTrack mb-2"]//div[@class="col-md-8 col-sm-8"]//p')->text();
            $block->filterXPath('//div[@class="col-md-12"]')->each(function (Crawler $checkpoint) use (&$result, $date) {
                $time = $checkpoint->filterXPath('//p[@class="text-white"][2]')->text();
                $dateTime = Carbon::parse($date . ' ' . $time);

                $result->statuses[] = new Status([
                    'title' => $checkpoint->filterXPath('//p[@class="text-white trackMobile font-weight-bold"]')->text(),
                    'location' => $checkpoint->filterXPath('//p[@class="text-white"][1]')->text(),
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute')
                ]);
            });
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'sa'
        ];
    }
}