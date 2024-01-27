<?php

namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class PostNordSwedenService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 134;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://api2.postnord.com/rest/shipment/v5/trackandtrace/ntt/shipment/recipientview?id=LZ043892870DK'), $trackNumber, [
            RequestOptions::HEADERS => [
                'authority' => 'api2.postnord.com',
                'method' => 'GET',
                'path' => '/rest/shipment/v5/trackandtrace/ntt/shipment/recipientview?id=LZ043892870DK&locale=en',
                'scheme' => 'https',
                'accept' => 'application/json, text/plain, */*',
                'accept-encoding' => 'gzip, deflate, br',
                'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'origin' => 'https://portal.postnord.com',
                'referer' => 'https://portal.postnord.com/',
                'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="98", "Google Chrome";v="98"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'sec-fetch-dest' => 'empty',
                'sec-fetch-mode' => 'cors',
                'sec-fetch-site' => 'same-site',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/98.0.4758.82 Safari/537.36',
                'x-bap-key' => 'web-ncp',
            ],
            RequestOptions::QUERY => [
                'id' => $trackNumber,
                'locale' => 'en'
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $request = json_decode($response->getBody()->getContents(), true);
        $result = $request['TrackingInformationResponse']['shipments'][0]['items'][0];

        $statuses = [];

        if (!empty($result)) {
            foreach ($result['events'] as $item) {
                $dateTime = Carbon::parse($item['eventTime']);

                $location = $item['location'];
                unset($location['name']);
                unset($location['locationId']);
                unset($location['locationType']);

                $location = implode(', ', $location);

                $statuses[] = new Status([
                    'title' => $item['eventDescription'],
                    'location' => $location,
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                ]);
            }

            return new Parcel([
                'statuses' => $statuses
            ]);
        }

        return false;
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}SE',
            'B[A-Z]{1}[0-9]{9}SE',
            'C[A-Z]{1}[0-9]{9}SE',
            'E[A-Z]{1}[0-9]{9}SE',
            'L[A-Z]{1}[0-9]{9}SE',
            'R[A-Z]{1}[0-9]{9}SE',
            'S[A-Z]{1}[0-9]{9}SE',
            'U[A-Z]{1}[0-9]{9}SE',
            'V[A-Z]{1}[0-9]{9}SE',
            '[0-9]{11}SE',
            '813[0-9]{7}',
            '814[0-9]{7}'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }
}