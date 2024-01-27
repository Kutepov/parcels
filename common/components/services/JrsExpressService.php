<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class JrsExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 444;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        $airBill = '';
        $trackingCode = '';
        if  (str_contains($trackNumber, '-')) {
            [$airBill, $trackingCode] = explode('-', $trackNumber);
        }
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://jrs-api.azurewebsites.net/api/CustomerTracking?airbill=' . $airBill . '&trackingCode=' . $trackingCode), $trackNumber, [
            RequestOptions::HEADERS => [
                'Accept' => '*/*',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Connection' => 'keep-alive',
                'Host' => 'jrs-api.azurewebsites.net',
                'Origin' => 'https://jrs-express.com',
                'Referer' => 'https://jrs-express.com/',
                'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="97", "Chromium";v="97"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'cross-site',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.71 Safari/537.36',
            ]
        ]);
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2,7}-[0-9]{3,4}HR' // 14767-5121
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);

        if (count($json) === 1 && $json[0]['DeliveryRemarks'] === 'Delivery status not encoded.') {
            return false;
        }

        $result = new Parcel();

        foreach ($json as $checkpoint) {
            [$month, $day, $year] = explode('/', $checkpoint['StatusDate']);
            $dateTime = Carbon::parse($day . '-' . $month . '-' . $year . ' '. $checkpoint['StatusTime'] ?: '');

            $result->statuses[] = new Status([
                'title' => $checkpoint['DeliveryStatus'],
                'location' => $checkpoint['Receiver'],
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'ph',
        ];
    }
}