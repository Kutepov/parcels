<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class BluedartService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, BatchTrackInterface
{
    public $id = 431;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.bluedart.com/web/guest/trackdartresult'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            $body = $response->getBody()->getContents();
            $dom = new Crawler($body);
            $authToken = $this->getAuthToken($body);
            $getParams = [
                'p_p_id' => 'innertrackdartportlet_WAR_Track_Dartportlet_INSTANCE_locationportlet',
                'p_p_lifecycle' => '1',
                'p_p_state' => 'normal',
                'p_p_mode' => 'view',
                '_innertrackdartportlet_WAR_Track_Dartportlet_INSTANCE_locationportlet_javax.portlet.action' => 'intertrackAction',
                'p_auth' => $authToken,
                '_innertrackdartportlet_WAR_Track_Dartportlet_INSTANCE_locationportlet_trackingNo' => implode(',', (array) $trackNumber),
                '_innertrackdartportlet_WAR_Track_Dartportlet_INSTANCE_locationportlet_selectedVal' => '0',
            ];
            $postParams = [
                '_innertrackdartportlet_WAR_Track_Dartportlet_INSTANCE_locationportlet_formDate' => $dom->filterXPath('//form[@id="_innertrackdartportlet_WAR_Track_Dartportlet_INSTANCE_locationportlet_targetInner"]//input[@id="_innertrackdartportlet_WAR_Track_Dartportlet_INSTANCE_locationportlet_formDate"]')->attr('value'),
                'radioBtn' => '0',
                'trackingNos' => implode(',', (array) $trackNumber),
            ];
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.bluedart.com/web/guest/trackdartresult?' . http_build_query($getParams)), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Cache-Control' => 'max-age=0',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Host' => 'www.bluedart.com',
                    'Origin' => 'https://www.bluedart.com',
                    'Referer' => 'https://www.bluedart.com/web/guest/trackdartresult',
                    'sec-ch-ua' => '"Chromium";v="94", "Google Chrome";v="94", ";Not A Brand";v="99"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'same-origin',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/94.0.4606.81 Safari/537.36',
                ],
                RequestOptions::FORM_PARAMS => $postParams,
            ]);
        });
    }

    private function getAuthToken($data)
    {
        $start = stripos($data, "authToken = '");
        $length = stripos($data, 'Liferay.currentURL');
        return substr($data, $start + 13, $length-$start-23);

    }

    public function batchTrackMaxCount()
    {
        return 10;
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function parseResponse($response, $trackNumber)
    {
        $content = $response->getBody()->getContents();
        $dom = new Crawler($content);

        if (!$dom->filterXPath('//div[@id="SCAN'.$trackNumber.'"]')->count()) {
            return false;
        }

        $result = new Parcel();
        $result->departureAddress = $dom->filterXPath('//div[@id="SHIP'.$trackNumber.'"]//tbody//tr[3]//td')->text();
        $result->destinationAddress = $dom->filterXPath('//div[@id="SHIP'.$trackNumber.'"]//tbody//tr[4]//td')->text();

        $dom->filterXPath('//div[@id="SCAN'.$trackNumber.'"]//tbody//tr')->each(function (Crawler $node) use (&$result) {
            if ($node->filterXPath('//td')->count() === 1) {
                return;
            }
            $date = Carbon::parse($node->filterXPath('//td[3]')->text() . ' ' . $node->filterXPath('//td[4]')->text());

            $result->statuses[] = new Status([
                'title' => $node->filterXPath('//td[2]')->text(),
                'location' => $node->filterXPath('//td[1]')->text(),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        });

        return $result;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackNumberRules(): array
    {
        return [
            '78517\d{6}' //78517794504
        ];
    }

    public function restrictCountries()
    {
        return ['in', 'us'];
    }
}