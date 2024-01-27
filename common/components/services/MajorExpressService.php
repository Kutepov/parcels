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

class MajorExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 435;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://major-express.ru/Trace.aspx'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            $data = $response->getBody()->getContents();
            $dom = new Crawler($data);

            $postParams = [
                '__EVENTTARGET' => $dom->filterXPath('//input[@id="__EVENTTARGET"]')->attr('value'),
                '__EVENTARGUMENT' => $dom->filterXPath('//input[@id="__EVENTARGUMENT"]')->attr('value'),
                '__VIEWSTATE' => $dom->filterXPath('//input[@id="__VIEWSTATE"]')->attr('value'),
                '__VIEWSTATEGENERATOR' => $dom->filterXPath('//input[@id="__VIEWSTATEGENERATOR"]')->attr('value'),
                '__PREVIOUSPAGE' => $dom->filterXPath('//input[@id="__PREVIOUSPAGE"]')->attr('value'),
                '__EVENTVALIDATION' => $dom->filterXPath('//input[@id="__EVENTVALIDATION"]')->attr('value'),
                'tbPopLog_Raw' => $dom->filterXPath('//input[@id="tbPopLog_Raw"]')->attr('value'),
                'ctl00$tbPopLog' => 'Логин',
                'tbPopPwd_Raw' => $dom->filterXPath('//input[@id="tbPopPwd_Raw"]')->attr('value'),
                'ctl00$tbPopPwd' => 'Пароль',
                'ctl00$chbRemember' => 'U',
                'ContentPlaceHolder1_cbProduct_VI' => '1',
                'ctl00$ContentPlaceHolder1$cbProduct' => 'Экспресс-доставка',
                'ContentPlaceHolder1_cbProduct_DDDWS' => '0:0:-1:-10000:-10000:0:0:0:1:0:0:0',
                'ContentPlaceHolder1_cbProduct_DDD_LDeletedItems' => '',
                'ContentPlaceHolder1_cbProduct_DDD_LInsertedItems' => '',
                'ContentPlaceHolder1_cbProduct_DDD_LCustomCallback' => '',
                'ctl00$ContentPlaceHolder1$cbProduct$DDD$L' => '1',
                'ctl00$ContentPlaceHolder1$rbWBNumber' => 'C',
                'ctl00$ContentPlaceHolder1$rbWBOldNumber' => 'I',
                'ContentPlaceHolder1_InvoiceNumber_Raw' => $trackNumber,
                'ctl00$ContentPlaceHolder1$InvoiceNumber' => $trackNumber,
                'ctl00$ContentPlaceHolder1$btnCheck' => 'submit',
                'ctl00$ContentPlaceHolder1$gvHistory$DXSelInput' => '',
                'ctl00$ContentPlaceHolder1$gvHistory$DXKVInput' => '[]',
                'ContentPlaceHolder1_DeliveryBlock_cbDeliveryProduct_VI' => '',
                'ctl00$ContentPlaceHolder1$DeliveryBlock$cbDeliveryProduct' => 'Выберите продукт',
                'ContentPlaceHolder1_DeliveryBlock_cbDeliveryProduct_DDDWS' => '0:0:-1:-10000:-10000:0:0:0:1:0:0:0',
                'ContentPlaceHolder1_DeliveryBlock_cbDeliveryProduct_DDD_LDeletedItems' => '',
                'ContentPlaceHolder1_DeliveryBlock_cbDeliveryProduct_DDD_LInsertedItems' => '',
                'ContentPlaceHolder1_DeliveryBlock_cbDeliveryProduct_DDD_LCustomCallback' => '',
                'ctl00$ContentPlaceHolder1$DeliveryBlock$cbDeliveryProduct$DDD$L' => '',
                'ContentPlaceHolder1_DeliveryBlock_InvoiceNumber_Raw' => '',
                'ctl00$ContentPlaceHolder1$DeliveryBlock$InvoiceNumber' => 'Введите номер накладной',
                'DXScript' => '1_187,1_101,1_130,1_137,1_180,1_124,1_121,1_105,1_141,1_129,1_98,1_172,1_170,1_132,1_120,1_154',
                'DXCss' => '1_7,1_16,1_8,1_6,1_14,1_1,1_11,1_10,styles.css',
            ];

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://major-express.ru/Trace.aspx'), $trackNumber, [
                RequestOptions::FORM_PARAMS => $postParams,
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Cache-Control' => 'max-age=0',
                    'Connection' => 'keep-alive',
                    'Content-Length' => '11753',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Host' => 'major-express.ru',
                    'Origin' => 'https://major-express.ru',
                    'Referer' => 'https://major-express.ru/Trace.aspx',
                    'sec-ch-ua' => '"Chromium";v="94", "Google Chrome";v="94", ";Not A Brand";v="99"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'same-origin',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/94.0.4606.81 Safari/537.36',
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = mb_convert_encoding($response->getBody()->getContents(), 'windows-1251', 'utf-8');
        $dom = new Crawler($data);
        $result = new Parcel();

        if (!$dom->filterXPath('//table[@id="ContentPlaceHolder1_gvHistory_DXMainTable"]')->count()) {
            return false;
        }


        $dom->filterXPath('//table[@id="ContentPlaceHolder1_gvHistory_DXMainTable"]//tr[@class="dxgvDataRow"]')->each(function (Crawler $node) use (&$result) {
            $date = Carbon::parse($node->filterXPath('//td[4]')->text() . ' ' . $node->filterXPath('//td[5]')->text());
            $result->statuses[] = (new Status([
                'title' => $node->filterXPath('//td[1]')->text(),
                'location' => $node->filterXPath('//td[3]')->text(),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]));
        });

        $result->departureAddress = $dom->filterXPath('//table[@id="ContentPlaceHolder1_gvDelivery"]//table//tr[3]//td[2]')->text();
        $result->destinationAddress = $dom->filterXPath('//table[@id="ContentPlaceHolder1_gvDelivery"]//table//tr[4]//td[2]')->text();

        return $result;
    }

    public function trackNumberRules(): array
    {
        return [
            '1[0-9]{9}' //1437695529
        ];
    }

    public function restrictCountries()
    {
        return [
            'ru'
        ];
    }
}