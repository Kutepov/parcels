<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use yii\helpers\Json;

class SwishipService extends BaseService implements ValidateTrackNumberInterface
{
    public $messages;

    private const US_PATTERN = 'TB[A-Z]{1}[0-9]{12}';
    private const ALL_DOMAINS = [
        'ca' => 'ca',
        'us' => 'com',
        'au' => 'com.au',
        'uk' => 'co.uk',
        'de' => 'de',
        'es' => 'es',
        'fr' => 'fr',
        'it' => 'it',
        'jp' => 'jp'
    ];
    private const DEFAULT_DOMAIN = 'com';
    private const DEFAULT_EU_DOMAIN = 'de';
    private const AVAILABLE_LANGUAGES = [
        'en' => 'en-US',
        'es' => 'es-ES',
        'it' => 'it-IT',
        'fr' => 'fr-FR',
        'de' => 'de-DE',
        'ja' => 'ja-JP',
        'zh' => 'zh-CN',
        'ko' => 'ko-KR'
    ];
    private const DEFAULT_LANGUAGE = 'en-US';

    public function track($trackNumber)
    {
        $parcel = \frontend\models\Parcel::findByTrackNumber($trackNumber);
        $domain = $this->getDomainForParcel($parcel);
        $language = $this->getLanguageForParcel($parcel);


        $messages = $this->getWithProxy('https://www.swiship.' . $domain . '/i18n/' . $language . '.json');

        $this->messages = Json::decode($messages);

        $check = $this->postWithProxy('https://www.swiship.' . $domain . '/api/getAllPackageInfo', [
            RequestOptions::HEADERS => ['Content-Type' => 'application/json'],
            RequestOptions::JSON => ['trackingNumber' => $trackNumber],
            'retry_on_status' => [403, 429],
        ]);

        $check = Json::decode($check);
        $result = $this->postWithProxy('https://www.swiship.' . $domain . '/api/getPackageTrackingDetails', [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Connection' => 'keep-alive',
                'Content-Type' => 'application/json;charset=UTF-8',
                'Host' => 'www.swiship.com',
                'Origin' => 'https://www.swiship.com',
                'Referer' => 'https://www.swiship.com/track/?id=' . $trackNumber,
                'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="102", "Google Chrome";v="102"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'same-origin',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/102.0.0.0 Safari/537.36',
            ],
            RequestOptions::JSON => [
                'trackingNumber' => $trackNumber,
                'shipMethod' => $check['packageInfoList'][0]['shipMethod'],
            ]
        ], true);

        return $this->parseResponse($result);
    }

    private function parseResponse(ResponseInterface $response)
    {
        foreach (json_decode($response->getBody()->getContents(), true)['trackingEvents'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['eventDate']);

            $statuses[] = new Status([
                'title' => $this->messages[$checkpoint['eventDescription']] ?? $checkpoint['eventDescription'],
                'location' => $checkpoint['eventAddress'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return isset($statuses) ? new Parcel(['statuses' => $statuses]) : false;
    }

    private function getDomainForParcel(\common\models\Parcel $parcel): string
    {
        if (isset(self::ALL_DOMAINS[$parcel->geo])) {
            return self::ALL_DOMAINS[$parcel->geo];
        }

        if (preg_match('#^' . self::US_PATTERN . '$#i', $parcel->track_number)) {
            return self::DEFAULT_DOMAIN;
        }

        return self::DEFAULT_EU_DOMAIN;
    }

    private function getLanguageForParcel(\common\models\Parcel $parcel): string
    {
        return self::AVAILABLE_LANGUAGES[$parcel->language] ?? self::DEFAULT_LANGUAGE;
    }

    public function trackNumberRules(): array
    {
        return [
            self::US_PATTERN,
            '[A-Z]{2}[0-9]{10}'
        ];
    }
}