<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class YusenLogisticsService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, BatchTrackInterface
{
    public $id = 450;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://rt.yusen-logistics.com/yusenvantagefocusapi/api/anonymous/getShipmentsFromYunas'), $trackNumber, [
            RequestOptions::QUERY => [
                'searchObj' => implode(',', (array)$trackNumber),
                'searchBy' => '1'
            ],
            RequestOptions::HEADERS => [
                'authority' => 'rt.yusen-logistics.com',
                'method' => 'GET',
                'path' => '/yusenvantagefocusapi/api/anonymous/getShipmentsFromYunas?searchObj=' . implode(',', (array)$trackNumber) . '&searchBy=1',
                'scheme' => 'https',
                'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'accept-encoding' => 'gzip, deflate, br',
                'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'cache-control' => 'no-cache',
                'pragma' => 'no-cache',
                'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="97", "Chromium";v="97"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'sec-fetch-dest' => 'document',
                'sec-fetch-mode' => 'navigate',
                'sec-fetch-site' => 'none',
                'sec-fetch-user' => '?1',
                'upgrade-insecure-requests' => '1',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.71 Safari/537.36',
            ]
        ]);
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{3}[0-9]{8}' // YHK04729572
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);
        $json = $json['responseEntity']['body']['responseList'];

        if (!count($json)) {
            return false;
        }

        $result = new Parcel();

        foreach ($json as $track) {

            if ($track['data']['universalShipment']['shipment']['subShipmentCollection']['subShipment']['wayBillNumber'] === $trackNumber) {
                foreach ($track['data']['universalShipment']['shipment']['subShipmentCollection']['subShipment']['milestoneCollection']['milestone'] as $checkpoint) {
                    $date = $checkpoint['actualDate'] ?: $checkpoint['estimatedDate'];
                    if ($date) {
                        $dateTime = Carbon::parse($date);
                        $result->statuses[] = new Status([
                            'title' => $checkpoint['description'],
                            'location' => $checkpoint['location']['place'] ?? '',
                            'date' => $dateTime->timestamp,
                            'dateVal' => $dateTime->toDateString(),
                            'timeVal' => $dateTime->toTimeString('minute'),
                        ]);
                    }
                }
            }

        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'dk',
            'se',
            'mx',
            'cz',
            'no'
        ];
    }

    public function batchTrackMaxCount()
    {
        return 5;
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

}