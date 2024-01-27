<?php

namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class GhanaPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 148;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://globaltracktrace.ptc.post/gtt.web/Search.aspx?style=1'), $trackNumber, [], function (ResponseInterface $response) use ($trackNumber) {
            $dom = new Crawler($response->getBody()->getContents());
            $postData = [
                'scriptManager' => 'updatePanelMain|btnSearch',
                '__LASTFOCUS' => '',
                'txtItemID' => $trackNumber,
                '__EVENTTARGET' => '',
                '__EVENTARGUMENT' => '',
                '__VIEWSTATE' => $dom->filterXPath('//input[@id="__VIEWSTATE"]')->attr('value'),
                '__VIEWSTATEGENERATOR' => $dom->filterXPath('//input[@id="__VIEWSTATEGENERATOR"]')->attr('value'),
                '__EVENTVALIDATION' => $dom->filterXPath('//input[@id="__EVENTVALIDATION"]')->attr('value'),
                '__ASYNCPOST' => 'true',
                'btnSearch' => 'Search',
            ];

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://globaltracktrace.ptc.post/gtt.web/Search.aspx?style=1'), $trackNumber, [
                RequestOptions::HEADERS => [
                    'Accept' => '*/*',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Cache-Control' => 'no-cache',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Host' => 'globaltracktrace.ptc.post',
                    'Origin:' => ' http://globaltracktrace.ptc.post',
                    'Referer' => 'http://globaltracktrace.ptc.post/gtt.web/Search.aspx?style=1',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.99 Safari/537.36',
                    'X-MicrosoftAjax' => 'Delta=true',
                    'X-Requested-With' => 'XMLHttpRequest',
                ],
                RequestOptions::FORM_PARAMS => $postData,
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $content = $response->getBody()->getContents();

        $dom = new Crawler($content);

        if (!$dom->filterXPath('//div[@id="resultsPanel"]//table[2]')->count()) {
            return false;
        }

        $result = new Parcel();
        $result->destinationCountry = $dom->filterXPath('//div[@id="resultsPanel"]//table[1]//tr[2]//td[3]')->text();
        $result->departureCountry = $dom->filterXPath('//div[@id="resultsPanel"]//table[1]//tr[2]//td[2]')->text();

        $dom->filterXPath('//div[@id="resultsPanel"]//table[2]//tr')->each(function (Crawler $checkpoint) use (&$result) {
            if (!$checkpoint->filterXPath('//td')->count()) {
                return false;
            }

            $date = Carbon::parse($checkpoint->filterXPath('//td[1]')->text());
            $result->statuses[] = (new Status([
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'title' => $checkpoint->filterXPath('//td[2]')->text(),
                'location' => $checkpoint->filterXPath('//td[3]')->text(),
            ]));

            return true;
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}LC',
            'C[A-Z]{1}[0-9]{9}LC',
            'E[A-Z]{1}[0-9]{9}LC',
            'L[A-Z]{1}[0-9]{9}LC',
            'R[A-Z]{1}[0-9]{9}LC',
            'S[A-Z]{1}[0-9]{9}LC',
            'V[A-Z]{1}[0-9]{9}LC'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }
}