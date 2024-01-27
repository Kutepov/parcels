<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\AntiCaptcha\Tasks\Letters;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Yii;

class ZtoService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface, CountryRestrictionInterface
{
    public $id = 441;

    /** @var Client */
    private $antiCaptchaService;

    private $captchaId = null;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }


    public function track($trackNumber)
    {
        if (!($token = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $token)->wait();
    }

    private function request($trackNumber, $token)
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://hdgateway.zto.com/listOrderBillDetail'), $trackNumber, [
            RequestOptions::HEADERS => [
                'Accept' => '*/*',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Connection' => 'keep-alive',
                'Content-Type' => 'application/json',
                'Host' => 'hdgateway.zto.com',
                'Origin' => 'https://www.zto.com',
                'Referer' => 'https://www.zto.com/',
                'sec-ch-ua' => '"Google Chrome";v="95", "Chromium";v="95", ";Not A Brand";v="99"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'same-site',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/95.0.4638.54 Safari/537.36',
                'x-captcha-code' => $token,
                'x-captcha-id' => $this->captchaId,
                'x-clientCode' => 'pc',
                'x-token' => 'null',
            ],
            RequestOptions::JSON => [
                'codes' => [
                    0 => ['billCode' => $trackNumber]
                ]
            ]
        ]);

    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($token = \common\models\redis\Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token);
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);

        if (!isset($json['result'][0]['billTraces'])) {
            return false;
        }

        $result = new Parcel();

        foreach ($json['result'][0]['billTraces'] as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint['scanDate']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['desc'],
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);

        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function preheatCaptcha()
    {
        $data = $this->post('https://mapi.zto.com/captcha/image');

        $data = json_decode($data, true);
        $this->captchaId = $data['result']['id'];

        if ($token = $this->antiCaptchaService->resolve(new Letters([
            'body' => str_replace('data:image/gif;base64,', '', $data['result']['image']),
        ]))) {
            return $token;
        }

        return null;
    }

    public function trackNumberRules(): array
    {
        return [
        ];
    }

    public function captchaLifeTime(): int
    {
        return 115;
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
            'ch',
            'hk',
            'us',
            'tw'
        ];
    }

}