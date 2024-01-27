<?php namespace common\components\services;

use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use yii;

class DpdRuService extends BaseService implements ServiceInterface, ValidateTrackNumberInterface, PriorValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 76;
    private $url = 'https://www.dpd.ru/ols/trace2/standard.do2?method:search';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.dpd.ru/'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            return $this->sendAsyncRequestWithProxy(new Request('POST', $this->url), $trackNumber, [
                RequestOptions::FORM_PARAMS => [
                    'orderNum' => $trackNumber,
                    'orderId' => ''
                ],
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'Accept' => '*/*',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Host' => 'www.dpd.ru',
                    'Origin' => 'https://www.dpd.ru',
                    'Referer' => 'https://www.dpd.ru/dpd/search/search.do2',
                    'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="102", "Google Chrome";v="102"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'Sec-Fetch-Dest' => 'empty',
                    'Sec-Fetch-Mode' => 'cors',
                    'Sec-Fetch-Site' => 'same-origin',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/102.0.0.0 Safari/537.36',
                    'X-Requested-With' => 'XMLHttpRequest',
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $body = $response->getBody()->getContents();

        if (stristr($body, 'Информация по вашему запросу не найдена')) {
            return false;
        }

        $data = rmnl($body);

        $result = new Parcel();

        if (preg_match('#<tr(?: class="table_colour_(?:five|four) height21"|)> <td>Пункт отправления</td> <td>(.*?)</td> </tr>#si', $data, $m)) {
            $result->departureAddress = trim($m[1]);
        }
        if (preg_match('#<tr(?: class="table_colour_(?:five|four) height21"|)> <td>Пункт назначения</td> <td>(.*?)</td> </tr>#si', $data, $m)) {
            $result->destinationAddress = trim($m[1]);
        }
        if (preg_match('#<tr(?: class="table_colour_(?:five|four) height21"|)> <td>Физический вес \(кг\)</td> <td>(.*?)</td> </tr>#si', $data, $m)) {
            $result->weight = trim($m[1]) * 1000;
        }

        if (preg_match('#<table class="module_table hide" id="trackHistory">(.*?)</table>#si', $data, $m)) {
            if (preg_match_all('#<tr class="table_colour_(?:five|four) height21"> <td>(.*?)(?:</td>| )<td>(.*?)</td> </tr>#si', $m[1], $checkpoints, PREG_SET_ORDER)) {
                foreach ($checkpoints as $k => $checkpoint) {
                    $result->statuses[] = new Status([
                        'title' => $checkpoint[2],
                        'date' => Yii::$app->formatter->asTimestamp($checkpoint[1])
                    ]);
                }
            }
        }

        return $result;
    }

    public function priorTrackNumberRules(): array
    {
        return $this->trackNumberRules();
    }

    public function trackNumberRules(): array
    {
        return [
            'RU\d{9}',
            'D\d{9}'
        ];
    }
}