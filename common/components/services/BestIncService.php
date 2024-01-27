<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class BestIncService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, BatchTrackInterface
{
    public $id = 457;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        $formParams = [
            'expressids' => (array)$trackNumber,
            'picverifycode' => ''
        ];
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://api.best-inc.co.th/express/expresslistinfo'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'req' => json_encode($formParams)
            ],
            RequestOptions::HEADERS => [
                'accept' => 'application/json',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'authorization' => 'Bearer null',
                'Connection' => 'keep-alive',
                'content-type' => 'application/x-www-form-urlencoded',
                'Host' => 'api.best-inc.co.th',
                'Origin' => 'https://www.best-inc.co.th',
                'Referer' => 'https://www.best-inc.co.th/',
                'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="97", "Chromium";v="97"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'same-site',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.99 Safari/537.36',
                'x-auth-type' => 'WEB',
                'x-lan' => 'EN',
                'x-timezone-offset:' => '5',
            ]
        ]);
    }


    public function trackNumberRules(): array
    {
        return [
            '[0-9]{14}', //66850326455220
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);

        $result = new Parcel();

        foreach ($json['data']['expresslist'] as $track) {
            if ($track['expressid'] === $trackNumber) {
                foreach ($track['tracedetails'] as $checkpoint) {
                    $date = Carbon::parse($checkpoint['accepttime']);
                    $result->statuses[] = new Status([
                        'title' => $checkpoint['remark'],
                        'location' => $checkpoint['acceptaddress'],
                        'date' => $date->timestamp,
                        'dateVal' => $date->toDateString(),
                        'timeVal' => $date->toTimeString('minute')
                    ]);
                }
            }
        }


        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'th',
        ];
    }

    public function batchTrackMaxCount()
    {
        return 20;
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }
}