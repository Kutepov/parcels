<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use Yii;
use yii\base\BaseObject;

class JetService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public function __construct($data = null)
    {
        parent::__construct($data);
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->request($trackNumber);
    }

    public function track($trackNumber)
    {
        return $this->request($trackNumber)->wait();
    }

    private function request($trackNumber)
    {

        $pId = md5(Yii::$app->security->generateRandomString());
        $pst = $pId.'j&t2020app!@#';


        return  $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.jet.co.id/index/router/index.html'), $trackNumber, [
            RequestOptions::HEADERS => [
                'authority' => 'www.jet.co.id',
                'method' => 'POST',
                'path' => '/index/router/index.html',
                'scheme' => 'https',
                'accept' => 'application/json, text/javascript, */*; q=0.01',
                'accept-encoding' => 'gzip, deflate, br',
                'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'content-type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                'origin' => 'https://www.jet.co.id',
                'referer' => 'https://www.jet.co.id/track',
                'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="90", "Google Chrome";v="90"',
                'sec-ch-ua-mobile' => '?0',
                'sec-fetch-dest' => 'empty',
                'sec-fetch-mode' => 'cors',
                'sec-fetch-site' => 'same-origin',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.93 Safari/537.36',
                'x-requested-with' => 'XMLHttpRequest',
                'x-simplypost-id' => $pId,
                'x-simplypost-signature' => $pst,
            ],
            RequestOptions::FORM_PARAMS => [
                'method' => 'app.findTrack',
                'data[billcode]' => $trackNumber,
                'data[lang]' => 'en',
                'data[source]' => '3',
                'pId' => $pId,
                'pst' => $pst
            ],
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $json = json_decode(
            json_decode($data)
        );

        $jsonData = json_decode($json->data);


        $result = new Parcel();

        foreach ($jsonData->details as $checkpoint) {
            $dateStr = $checkpoint->scantime;
            $dateTime = Carbon::parse($dateStr);
            $result->statuses[] = new Status([
                'title' => $checkpoint->scanstatus,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
                'location' => $checkpoint->city
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{10}' //JP0812204813
        ];
    }
}