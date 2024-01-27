<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class RastreamentocorreiosService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        }
        catch (\Exception $exception) {
        }
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' // ON292890354BR
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://rastreamentocorreios.info/consulta/' . $trackNumber), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], null, function (ServerException $exception) use ($trackNumber, $jar) {
            $dom = (new Dom())->loadStr($exception->getResponse()->getBody()->getContents());
            $form = [
                'r' => $dom->find('form')->find('*[name="r"]')->getAttribute('value'),
                'md' => $dom->find('form')->find('*[name="md"]')->getAttribute('value'),
                'jschl_vc' => $dom->find('form')->find('*[name="jschl_vc"]')->getAttribute('value'),
                'pass' => $dom->find('form')->find('*[name="pass"]')->getAttribute('value'),
                'cf_ch_verify' => 'plat',
            ];
            $action = $dom->find('form')->getAttribute('action');
            //TODO: Не работает
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://rastreamentocorreios.info' . $action), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'authority' => 'rastreamentocorreios.info',
                    'method' => 'POST',
                    'path' => $action,
                    'scheme' => 'https',
                    'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                    'accept-encoding' => 'gzip, deflate, br',
                    'accept-language' => 'ru-RU,ru;q=0.9',
                    'cache-control' => 'max-age=0',
                    'content-type' => 'application/x-www-form-urlencoded',
                    'origin' => 'https://rastreamentocorreios.info',
                    'referer' => 'https://rastreamentocorreios.info/consulta/' . $trackNumber,
                    'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="91", "Chromium";v="91"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-fetch-dest' => 'document',
                    'sec-fetch-mode' => 'navigate',
                    'sec-fetch-site' => 'same-origin',
                    'upgrade-insecure-requests' => '1',
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.164 Safari/537.36',

                ],
                RequestOptions::FORM_PARAMS => $form
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        return false;
    }

}