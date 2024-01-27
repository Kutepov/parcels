<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use yii\helpers\Json;

class GTDService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 310;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://tk-kit.com/'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
            RequestOptions::HEADERS => [
               'accept-encoding' => 'gzip, deflate'
            ]
        ],  function (ResponseInterface $response) use ($jar, $trackNumber) {

            preg_match('#<meta name="csrf-token" content="(.*?)">#si', $response->getBody()->getContents(), $csrf);
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://tk-kit.com/site/trackload_end?send=1'), $trackNumber, [
                RequestOptions::FORM_PARAMS => [
                    '_csrf' => $csrf[1],
                    'TrackLoadForm[track_code]' => $trackNumber
                ],
                RequestOptions::DELAY => rand(1000, 2500),
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'authority' => 'tk-kit.com',
                    'method' => 'POST',
                    'path' => '/site/trackload_end?send=1',
                    'scheme' => 'https',
                    'accept' => 'application/json, text/javascript, */*; q=0.01',
                    'accept-encoding' => 'gzip, deflate, br',
                    'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'content-type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'origin' => 'https://tk-kit.com',
                    'referer' => 'https://tk-kit.com/',
                    'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="97", "Chromium";v="97"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => ' "Windows"',
                    'sec-fetch-dest' => 'empty',
                    'sec-fetch-mode' => 'cors',
                    'sec-fetch-site' => 'same-origin',
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.99 Safari/537.36',
                    'x-csrf-token' => $csrf[1],
                    'x-requested-with' => 'XMLHttpRequest',
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $response = Json::decode($response->getBody()->getContents());
        $result = new Parcel([
            'departureAddress' => $response['cityOut']['name'] ?? null,
            'destinationAddress' => $response['cityIn']['name'] ?? null,
            'departureCountryCode' => $response['cityOut']['country_code'] ?? null,
            'destinationCountryCode' => $response['cityIn']['country_code'] ?? null,
        ]);

        foreach ($response['status'] as $checkpoint) {
            try {
                $date = Carbon::parse($checkpoint['date'] . ' ' . $checkpoint['time']);
            } catch (\Throwable $e) {
                continue;
            }

            if (!$date) {
                continue;
            }

            $result->statuses[] = new Status([
                'title' => $checkpoint['text'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return $result;
    }

    public function trackNumberRules(): array
    {
        return [
            '[А-Я\d]{6}\d{10}'
        ];
    }

    public function restrictCountries()
    {
        return [
            'ru', 'kz', 'ua', 'by'
        ];
    }
}