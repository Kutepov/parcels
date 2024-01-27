<?php namespace common\components\services;

use common\components\AntiCaptcha\Client;
use common\components\AntiCaptcha\Tasks\Letters;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use Yii;

class ALLJOYService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface
{
    public $id = 179;

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

    private function request($trackNumber, $token)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://www.alljoylogistics.com/cx?dh=' . $trackNumber), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($token, $trackNumber, $jar) {

            $requestToken = $this->getRequestToken($response->getBody()->getContents());

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://www.alljoylogistics.com/cx?dh=' . $trackNumber), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json, text/javascript, */*; q=0.01',
                ],
                RequestOptions::FORM_PARAMS => [
                    'dh' => $trackNumber,
                    'captcha' => $token,
                    '_token' => $requestToken,
                ]
            ]);

        });
    }

    private function getRequestToken($data)
    {
        $start = stripos($data, '_token: "');
        $length = stripos($data, 'captcha: $');
        return substr($data, $start+9, $length-$start-28);
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
        //FIXME: Получаю ответ с сообщением, что данные ведены неверно
        dump($response->getBody()->getContents()); die;
        preg_match('/<table class="traceTable".*?>(.*?)<\/table>/sm', $response->getBody()->getContents(), $matches);

        if ($matches[1]) {
            preg_match_all('/<tr.*?>(.*?)<\/tr>/sm', $matches[1], $items);
            preg_match_all('/<td.*?>(.*?)<\/td>/sm', $items[1][2], $data);

            preg_match_all('/([0-9]{4}.*?)&nbsp;&nbsp;(.*?)</sm', $data[1][1], $data);

            foreach ($data[1] as $key => $value) {
                $statuses[] = new Status([
                    'title' => $data[2][$key],
                    'date' => $this->createDate($value)
                ]);
            }

            return new Parcel([
                'statuses' => $statuses
            ]);
        }

        return false;
    }

    public function preheatCaptcha()
    {
        $captchaImage = $this->get('http://www.alljoylogistics.com/captcha/default');

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

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{5}[0-9]{10}[A-Z]{2}', //ALJLK1012001973YQ
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

}