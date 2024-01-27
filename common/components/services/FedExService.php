<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\Country;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use stdClass;

class FedExService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, BatchTrackInterface
{
    public $id = 27;

    private $url = 'https://api.fedex.com/track/v2/shipments';
    public $mainAsyncCourier = true;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://api.fedex.com/auth/oauth/v2/token?client_id=l7b8ada987a4544ff7a839c8e1f6548eea&client_secret=f068e54eb5384e80978c154cd5ff0d72&grant_type=client_credentials&scope=oob'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
            RequestOptions::HEADERS => [
                'authority' => 'api.fedex.com',
                'method' => 'POST',
                'path' => '/auth/oauth/v2/token?client_id=l7b8ada987a4544ff7a839c8e1f6548eea&client_secret=f068e54eb5384e80978c154cd5ff0d72&grant_type=client_credentials&scope=oob',
                'scheme' => 'https',
                'accept' => 'application/json, text/plain, */*',
                'accept-encoding' => 'gzip, deflate, br',
                'accept-language' => 'ru-RU,ru;q=0.9',
                'content-length' => '0',
                'content-type' => 'application/x-www-form-urlencoded',
                'origin' => 'https://www.fedex.com',
                'referer' => 'https://www.fedex.com/fedextrack/?trknbr=' . $trackNumber,
                'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="99", "Google Chrome";v="99"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'sec-fetch-dest' => 'empty',
                'sec-fetch-mode' => 'cors',
                'sec-fetch-site' => 'same-site',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.4844.82 Safari/537.36',
            ],

        ], function (ResponseInterface $response) use ($trackNumber, $jar) {

            $token = json_decode($response->getBody()->getContents(), true)['access_token'];


            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://api.fedex.com/auth/oauth/v2/token'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::FORM_PARAMS => [
                    'grant_type' => 'client_credentials',
                    'client_id' => 'l7xx474b79016a4d4ec5a60bf7a7e5e7e6fe',
                    'client_secret' => '448399ccafaa4f62a4ed202fc5ef3a01',
                ],
                RequestOptions::HEADERS => [
                    'authority' => 'api.fedex.com',
                    'method' => 'POST',
                    'path' => '/auth/oauth/v2/token',
                    'scheme' => 'https',
                    'accept' => 'application/json, text/plain, */*',
                    'accept-encoding' => 'gzip, deflate, br',
                    'accept-language' => 'ru-RU,ru;q=0.9',
                    'content-type' => 'application/x-www-form-urlencoded;charset=UTF-8',
                    'origin' => 'https://www.fedex.com',
                    'referer' => 'https://www.fedex.com/fedextrack/?trknbr=' . $trackNumber,
                    'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="99", "Google Chrome";v="99"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'sec-fetch-dest' => 'empty',
                    'sec-fetch-mode' => 'cors',
                    'sec-fetch-site' => 'same-site',
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.4844.82 Safari/537.36'
                ],
            ], function (ResponseInterface $response) use ($trackNumber, $jar, $token) {
                $data = [
                    'appDeviceType' => 'WTRK',
                    'appType' => 'WTRK',
                    'supportCurrentLocation' => true,
                    'uniqueKey' => '',
                    'trackingInfo' => []
                ];

                foreach ((array)$trackNumber as $tn) {
                    $data['trackingInfo'][] = [
                        'trackNumberInfo' => [
                            'trackingNumber' => $tn,
                            'trackingQualifier' => '12023~271240879161~FDEG',
                            'trackingCarrier' => ''
                        ]
                    ];
                }

                return $this->sendAsyncRequestWithProxy(new Request('POST', $this->url), $trackNumber, [
                    RequestOptions::COOKIES => $jar,
                    RequestOptions::JSON => $data,
                    RequestOptions::HEADERS => [

                        'authority' => 'api.fedex.com',
                        'method' => 'POST',
                        'path' => '/track/v2/shipments',
                        'scheme' => 'https',
                        'accept' => 'application/json',
                        'accept-encoding' => 'gzip, deflate, br',
                        'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                        'authorization' => 'Bearer ' . $token,
                        'content-type' => 'application/json',
                        'origin' => 'https://www.fedex.com',
                        'referer' => 'https://www.fedex.com/fedextrack/?trknbr=' . $trackNumber,
                        'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="99", "Google Chrome";v="99"',
                        'sec-ch-ua-mobile' => '?0',
                        'sec-ch-ua-platform' => '"Windows"',
                        'sec-fetch-dest' => 'empty',
                        'sec-fetch-mode' => 'cors',
                        'sec-fetch-site' => 'same-site',
                        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.4844.82 Safari/537.36',
                        'x-clientid' => 'WTRK',
                        'x-locale' => 'en_US',
                        'x-requested-with' => 'XMLHttpRequest',
                        'x-version' => '1.0.0',
                    ]
                ]);

            });

        });

    }

    /**
     * @param Response|stdClass $response
     * @param string $trackNumber
     * @return Parcel|bool
     */
    public function parseResponse($response, $trackNumber)
    {
        $response = json_decode($response->getBody()->getContents(), true);

        $tnFound = false;
        foreach ($response['output']['packages'] as $package) {
            if ($package['trackingNbr'] == $trackNumber || $package['displayTrackingNbr'] == $trackNumber || in_array($trackNumber, $package['drTgGrp'])) {
                $response = $package;
                $tnFound = true;
                break;
            }
        }

        if (!$tnFound) {
            return false;
        }

        $result = new Parcel([
            'departureCountryCode' => $response['shipperCntryCD'],
            'destinationCountryCode' => $response['recipientCntryCD'],
            'extraInfo' => [
                'Service' => $response['serviceDesc'],
                'Terms' => $response['terms'],
                'Dimensions' => $response['dimensions'],
                'Packaging' => $response['packaging'],
                'Purchase order number' => implode(', ', (array)$response['purchaseOrderNbrList']),
                'Reference' => implode(', ', (array)$response['referenceList']),
                'Total pieces' => $response['totalPieces'],
            ]
        ]);

        $addresses = [
            'departure' => [],
            'destination' => []
        ];

        foreach (['shipper' => 'departure', 'recipient' => 'destination'] as $k => $v) {
            foreach (['City', 'StateCD', 'CntryCD', 'Addr1', 'Addr2', 'Zip'] as $p) {
                if ($value = $response[$k . $p]) {
                    $addresses[$v][] = $value;
                }
            }
        }

        foreach ($addresses as $k => $address) {
            if (count($address)) {
                $result->{$k . 'Address'} = implode(', ', $address);
            }
        }

        if ($response['pkgKgsWgt'] > 0) {
            $result->weight = $response['pkgKgsWgt'] * 1000;
        }

        if ($response['displayPkgWgt']) {
            $result->weightValue = $response['displayPkgWgt'];
        }

        if (isset($response['standardTransitDate']) && ($date = $response['standardTransitDate']['stdTransitDate'])) {
            $result->estimatedDeliveryTime = $this->createDate($date);
        }
        elseif ($response['shipDt']) {
            $result->estimatedDeliveryTime = $this->createDate($response['shipDt']);
        }


        $statuses = [];
        if (is_array($response['scanEventList']) && count($response['scanEventList']) && (count($response['scanEventList']) > 1 || (count($response['scanEventList']) == 1) && $response['scanEventList']['status']) || (count($response['scanEventList']) == 1 && !$response['scanEventList']['status'])) {
            foreach ($response['scanEventList'] as $checkpoint) {

                $dateString = $checkpoint['date'] . ' ' . $checkpoint['time'] . $checkpoint['gmtOffset'];
                $date = Carbon::parse($dateString);

                $location = $checkpoint['scanLocation'];

                $statuses[] = new Status([
                    'title' => $checkpoint['status'],
                    'location' => $location,
                    'date' => $this->createDate($dateString),
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                    'timezoneVal' => $date->offsetHours >= 0 ? '+' . $date->offsetHours : $date->offsetHours
                ]);
            }
        }

        $result->statuses = array_reverse($statuses);

        return $result;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    //TODO:: extra
    public function trackNumberRules(): array
    {
        return [
            'DT[0-9]{12}',
            '[0-9]{22}',
            '[0-9]{12}',
            '[0-9]{20}',
            '[0-9]{9}\-[0-9]{1}'
        ];
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 30;
    }
}