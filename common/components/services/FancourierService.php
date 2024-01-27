<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Yii;

class FancourierService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface, CountryRestrictionInterface
{
    public $id = 423;
    private $recaptchaKey = '6Lft1P8UAAAAAAajPInMKhDKHZyH4xScX8OdXpwc';

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }


    public function trackNumberRules(): array
    {
        return [
            '[0-9]{13}', //8145189060001
            'FAN[A-Z]{2}[0-9]{9}', //FANRE000012278
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($token = Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token);
    }

    public function track($trackNumber)
    {
        if (!($recaptcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $recaptcha->token)->wait();
    }

    private function request($trackNumber, $token)
    {
        $formParams = [
            'g-recaptcha-response' => $token,
            'bar_size' => 'x',
            'limba' => 'romana',
            'action' => 'get_awb',
        ];

        if (stripos($trackNumber, 'FAN') !== false) {
            $formParams['bill'] = $trackNumber;
            $formParams['awb'] = '';
        } else {
            $formParams['bill'] = '';
            $formParams['awb'] = $trackNumber;
        }

        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.fancourier.ro/wp-admin/admin-ajax.php'), $trackNumber, [
            RequestOptions::HEADERS => [
                'Accept' => '*/*',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept-Language'=> 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Connection'=> 'keep-alive',
                'Content-Type'=> 'application/x-www-form-urlencoded; charset=UTF-8',
                'Host'=> 'www.fancourier.ro',
                'Origin'=> 'https://www.fancourier.ro',
                'Referer'=> 'https://www.fancourier.ro/awb-tracking/',
                'sec-ch-ua'=> '"Google Chrome";v="93", " Not;A Brand";v="99", "Chromium";v="93"',
                'sec-ch-ua-mobile'=> '?0',
                'sec-ch-ua-platform'=> 'Windows',
                'Sec-Fetch-Dest'=> 'empty',
                'Sec-Fetch-Mode'=> 'cors',
                'Sec-Fetch-Site'=> 'same-origin',
                'User-Agent'=> 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.82 Safari/537.36',
                'X-Requested-With'=> 'XMLHttpRequest',
            ],
            RequestOptions::FORM_PARAMS => $formParams,
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $json = json_decode(json_decode($data), true);

        if ($json[1]['tip'] === 'eroare') {
            return false;
        }
        $result = new Parcel();

        foreach ($json[1] as $index =>  $checkpoint) {
            if (!is_integer($index)) {
                continue;
            }
            $dateTime = Carbon::parse($checkpoint['dstex']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['mstex'],
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }
        return (!empty($result->statuses)) ? $result : false;
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://www.fancourier.ro/awb-tracking/',
        ]))) {
            return new Recaptcha([
                'token' => $token
            ]);
        }

        return null;
    }

    public function captchaLifeTime(): int
    {
        return 120;
    }

    public function recaptchaVersion()
    {
        return 2;
    }

    public function maxPreheatProcesses()
    {
        return 8;
    }

    public function restrictCountries()
    {
        return [
            'ro', 'bg', 'md'
        ];
    }
}