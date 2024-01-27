<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class Post17Service extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 432;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://17post.kingtrans.cn/WebTrack?action=list'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
            RequestOptions::HEADERS => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'Accept-Encoding' => 'gzip, deflate',
                'Cache-Control' => 'max-age=0',
                'Connection' => 'keep-alive',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Host' => '17post.kingtrans.cn',
                'Origin' => 'http://17post.kingtrans.cn',
                'Referer' => 'http://17post.kingtrans.cn/WebTrack?action=list',
                'Upgrade-Insecure-Requests' => '1',
            ],
            RequestOptions::FORM_PARAMS => [
                'language' => 'zh',
                'istrack' => 'false',
                'bills' => $trackNumber,
                'Submin' => '查询',
            ]

        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://17post.kingtrans.cn/WebTrack?action=repeat'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'Accept' => 'application/xml, text/xml, */*; q=0.01',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Host' => '17post.kingtrans.cn',
                    'Origin' => 'http://17post.kingtrans.cn',
                    'Referer' => 'http://17post.kingtrans.cn/WebTrack?action=list',
                    'X-Requested-With' => 'XMLHttpRequest',

                ],
                RequestOptions::FORM_PARAMS => [
                    'index' => '0',
                    'billid' => $trackNumber,
                    'isRepeat' => 'no',
                    'language' => 'zh',
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $content = $response->getBody()->getContents();
        $xml = simplexml_load_string($content);
        $jsonString = json_encode($xml);
        $data = json_decode($jsonString, true);


        if (!isset($data['xout']['track']['trackitem'])) {
            return false;
        }

        $result = new Parcel();

        foreach ($data['xout']['track']['trackitem'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['@attributes']['sdate']);

            $result->statuses[] = new Status([
                'title' => $checkpoint['@attributes']['intro'],
                'location' => $checkpoint['@attributes']['place'],
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
        return [
            'EQTWL\d{10}YQ' //EQTWL1090973764YQ
        ];
    }

    public function restrictCountries()
    {
        return ['ch', 'us', 'ru', 'hk', 'kr'];
    }
}