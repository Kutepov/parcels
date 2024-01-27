<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class SkynetService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{12}' //239004007911
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        //FIXME: Ответ от сервера получаю без данных по треку, как будто форма не отправлялась
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://www.skynet.com.my/track'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://www.skynet.com.my/track'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'Content-Type' => 'multipart/form-data; boundary=hawbNoList:' . $trackNumber,
                    //'content-type' => 'application/x-www-form-urlencoded',
                    'Host' => 'www.skynet.com.my',
                    'authority' => 'www.skynet.com.my',
                    'method' => 'POST',
                    'path' => '/track',
                    'scheme' => 'https',
                    'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                    'accept-encoding' => 'gzip, deflate, br',
                    'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'cache-control' => 'max-age=0',
                    'origin' => 'https://www.skynet.com.my',
                    'referer' => 'https://www.skynet.com.my/track',
                    'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="96", "Google Chrome";v="96"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'sec-fetch-dest' => 'document',
                    'sec-fetch-mode' => 'navigate',
                    'sec-fetch-site' => 'same-origin',
                    'sec-fetch-user' => '?1',
                    'upgrade-insecure-requests' => '1',
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.110 Safari/537.36',
                ],
                RequestOptions::FORM_PARAMS => [
                    'hawbNoList' => $trackNumber,
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = (new Dom())->loadStr($data);

        if (!$dom->find('#trackDetails')->count()) {
            return false;
        }

        $result = new Parcel();
        $dateString = '';
        $itemNumber = 0;
        foreach ($dom->find('.trackItemFont') as $checkpoint) {

            if (!$checkpoint->find('td')->count()) {
                $dateString = $checkpoint->text;
            }
            else {
                $timeString = $dom->find('.trackTimeFont', $itemNumber)->text;
                $itemNumber++;
                $dateTime = Carbon::parse($dateString . ' ' . $timeString);
                $result->statuses[] = new Status([
                    'title' => $checkpoint->find('td', 2)->text,
                    'date' => $dateTime->timestamp,
                    'location' => $checkpoint->find('td', 3)->text,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                ]);
            }
        }

        return (!empty($result->statuses)) ? $result : false;
    }

}