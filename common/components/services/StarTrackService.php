<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class StarTrackService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 240;
    private $apiKey = 'nzsET4kyTEOBfkEZZ2ew2OGOby8GwNPa';

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://digitalapi.auspost.com.au/consignment/v2/consignments?q=' . $trackNumber), $trackNumber, [
            RequestOptions::HEADERS => [
                'Connection' => 'keep-alive',
                'Pragma' => 'no-cache',
                'Cache-Control' => 'no-cache',
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Encoding' => 'gzip',
                'X-DataDome-ClientID' => '.keep',
                'auth-key' => $this->apiKey,
                'Referer' => 'https://startrack.com.au/',
                'Accept-Language' => 'en;q=0.9;q=0.8,en-US;q=0.7',
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'cross-site',
            ]
        ], function (ResponseInterface $response) use ($trackNumber) {
            $data = json_decode($response->getBody()->getContents(), true);
            if (!count($data['errors'])) {
                return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://digitalapi.auspost.com.au' . $data['consignments'][0]['href'] . '?expand=articles,events'), $trackNumber, [
                    RequestOptions::HEADERS => [
                        'Connection' => 'keep-alive',
                        'Pragma' => 'no-cache',
                        'Cache-Control' => 'no-cache',
                        'Accept' => 'application/json, text/plain, */*',
                        'Accept-Encoding' => 'gzip',
                        'X-Datadome-Clientid' => '.keep',
                        'auth-key' => $this->apiKey,
                        'Accept-Language' => 'en;q=0.9;q=0.8,en-US;q=0.7',
                        'Sec-Fetch-Dest' => 'empty',
                        'Sec-Fetch-Mode' => 'cors',
                        'Sec-Fetch-Site' => 'cross-site',
                        'Referer' => 'https://startrack.com.au/'
                    ]
                ]);
            }
            else {
                return false;
            }
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        try {
            $estimatedDeliveryDate = $this->createDate($data['expectedDelivery']['between']['to'] ?? $data['expectedDelivery']['between']['from'], true);
        } catch (\Throwable $e) {
            $estimatedDeliveryDate = null;
        }

        $result = new Parcel([
            'estimatedDeliveryTime' => $estimatedDeliveryDate,
            'destinationAddress' => implode(', ', array_filter($data['destinationAddress']))
        ]);

        $info = $data['articles']['items'][0];

        foreach ($info['trackingEvents']['items'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['on']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['message'],
                'location' => $checkpoint['location'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return $result;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackNumberRules(): array
    {
        return [];
    }

    public function restrictCountries()
    {
        return ['au', 'ph'];
    }
}