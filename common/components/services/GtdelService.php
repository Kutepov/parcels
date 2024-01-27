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

class GtdelService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 436;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://spare.gtdel.com/'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            $data = $response->getBody()->getContents();
            $dom = new Crawler($data);
            $csrf = $dom->filterXPath('//form[@id="trackload-form_widget"]//input[@name="_csrf"]')->attr('value');
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://spare.gtdel.com/site/trackload_end?send=1'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'authority' => 'spare.gtdel.com',
                    'method' => 'POST',
                    'path' => '/site/trackload_end?send=1',
                    'scheme' => 'https',
                    'accept' => 'application/json, text/javascript, */*; q=0.01',
                    'accept-encoding' => 'gzip, deflate, br',
                    'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'content-type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'origin' => 'https://spare.gtdel.com',
                    'referer' => 'https://spare.gtdel.com/',
                    'sec-ch-ua' => '"Google Chrome";v="95", "Chromium";v="95", ";Not A Brand";v="99"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'sec-fetch-dest' => 'empty',
                    'sec-fetch-mode' => 'cors',
                    'sec-fetch-site' => 'same-origin',
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/95.0.4638.54 Safari/537.36',
                    'x-csrf-token' => $csrf,
                    'x-requested-with' => 'XMLHttpRequest',
                ],
                RequestOptions::FORM_PARAMS => [
                    '_csrf' => $csrf,
                    'TrackLoadForm[track_code]' => $trackNumber,
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        if (isset($data['trackloadform-track_code'])) {
            return false;
        }

        $result = new Parcel();
        $result->departureAddress = $data['cityOut']['name'];
        $result->destinationAddress = $data['address'];
        foreach ($data['status'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['date'] . ' ' . $checkpoint['time']);
            $result->statuses[] = (new Status([
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'title' => $checkpoint['text'],
            ]));
        }

        return $result;
    }

    public function trackNumberRules(): array
    {
        return [
            '[А-Яа-я]{6}01044[0-9]{5}', //НОВНЖК0104411734
            '01044[0-9]{5}', //0104411734
        ];
    }

    public function restrictCountries()
    {
        return [
            'ru', 'kz', 'uk',
        ];
    }

}