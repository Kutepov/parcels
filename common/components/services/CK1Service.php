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

class CK1Service extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface
{
    public $id = 178;
    public $captcha = true;
    private $url = 'http://www.chukou1.com';

    /** @var Client */
    private $antiCaptchaService;

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

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($token = \common\models\redis\Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token);
    }

    public function request($trackNumber, $token): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.chukou1.com/AJAX/TrackingOrders.aspx'), $trackNumber, [
            RequestOptions::QUERY => [
                'jsonpCallback' => 'jQuery18003460711343659455_1656338670950',
                'trackNo' => $trackNumber,
                'Identity' => 'Q0NG9cRENDQzlDQ0JGQ0NG2RENDRERDQ0RGQ0M4RA==',
                'verifycode' => $token,
                '__Identity' => 'Q0NC02OENDQTdDQzg5Q0M47QkNDQUNDQ0ZCQ0NGODlDRERDOUI5Q0NEOENDN0Q5OUFDQzlBRENDQkM5OUJEOUNEQw==',
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = str_replace('jQuery18003460711343659455_1656338670950([', '', $response->getBody()->getContents());
        $data = str_replace(']);', '', $data);
        $json = json_decode($data, true);

        if (!$json['Success']) {
            return false;
        }

        $result = new Parcel();

        foreach ($json['Result']['TrackDetails'] as $item) {
            $date = Carbon::parse($item['Date']);
            $result->statuses[] = new Status([
                'title' => $item['Desc'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $item['Location']
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{3}[0-9]{6}[A-Z]{1}[0-9]{14}'
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

    public function preheatCaptcha()
    {
        $captchaImage = $this->get('https://www.chukou1.com/verify/small.aspx?s=Q0NC02OENDQTdDQzg5Q0M47QkNDQUNDQ0ZCQ0NGODlDRERDOUI5Q0NEOENDN0Q5OUFDQzlBRENDQkM5OUJEOUNEQw==&r=49');

        if (!empty($captchaImage)) {
            $captcha = base64_encode($captchaImage);
        } else {
            return false;
        }


        if ($token = $this->antiCaptchaService->resolve(new Letters([
            'body' => $captcha,
        ]))) {
            return $token;
        }

        return null;
    }

}