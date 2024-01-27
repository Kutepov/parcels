<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class YDHService extends BaseService implements AsyncTrackingInterface, ValidateTrackNumberInterface, BatchTrackInterface
{

    public $id = 197;
    private $url = 'http://track.ydhex.com/track_query.aspx';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', $this->url), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            $jar->setCookie(new SetCookie([
                'Name' => 'i18next',
                'Value' => 'en',
                'Domain' => 'track.ydhex.com',
                'Path' => '/'
            ]));

            return $this->sendAsyncRequestWithProxy(new Request('POST', $this->url), $trackNumber, [
                RequestOptions::HEADERS => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Cache-Control' => 'max-age=0',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Host' => 'track.ydhex.com',
                    'Origin' => 'http://track.ydhex.com',
                    'Referer' => 'http://track.ydhex.com/track_query.aspx',
                    'Upgrade-Insecure-Requests' => '1',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.110 Safari/537.36',
                ],
                RequestOptions::FORM_PARAMS => [
                    '__VIEWSTATE' => '',
                    'system' => '',
                    'track_number' => is_array($trackNumber) ? implode("\n", $trackNumber) : $trackNumber,
                    'btnSearch' => '查询'
                ],
                RequestOptions::COOKIES => $jar
            ]);

        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = new Crawler($data);

        if ($dom->filterXPath('//td[@data-i18n="list.norecord"]')->count()) {
            return false;
        }

        $checkpoints = $dom->filterXPath('//span[contains(text(), "' . $trackNumber . '")]//ancestor::tr[@style="cursor: pointer"]//following::tr//td[@colspan="7"]//div[@class="vertical-container"]');

        $result = new Parcel();
        if ($checkpoints->count()) {
            $checkpoints->each(function (Crawler $checkpoint) use (&$result) {
                [$date, $location, , $title] = explode("\r\n" ,trim($checkpoint->filterXPath('//ul//li')->text()));
                $dateTime = Carbon::parse($date);
                $result->statuses[] = new Status([
                    'title' => $title,
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                    'location' => $location
                ]);
            });
        } else {
            $checkpoint = $dom->filterXPath('//span[contains(text(), "' . $trackNumber . '")]//ancestor::tr[@style="cursor: pointer"]//div[@class="vote-info"]//li[@style="margin-top: 20px;"]');
            $date = trim($checkpoint->filterXPath('//span[@class="col-md-6"]//span[@class="msgcss"]')->text());

            [$date, $timeTitle] = explode(' ', $date);
            [$time, $title] = explode(chr(0xC2).chr(0xA0), $timeTitle);
            $dateTime = Carbon::parse($date . ' ' . $time);
            $result->statuses[] = new Status([
                'title' => $title,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            'YDH[A-Z]{2}\d{10}YQ',
            'TECSP\d{8}'
        ];
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 10;
    }
}