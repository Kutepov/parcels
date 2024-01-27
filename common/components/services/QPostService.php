<?php namespace common\components\services;

use common\components\AntiCaptcha\Client;
use common\components\AntiCaptcha\Tasks\Letters;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Yii;

class QPostService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, CaptchaPreheatInterface
{
    public $id = 142;

    /** @var Client */
    private $antiCaptchaService;

    private $jar;

    public function __construct($data = null)
    {
        $this->jar = new CookieJar();
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
        return $this->sendAsyncRequestWithProxy('', '');
    }

    public function parseResponse($response, $trackNumber)
    {
        return false;
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}QA',
            'C[A-Z]{1}[0-9]{9}QA',
            'E[A-Z]{1}[0-9]{9}QA',
            'L[A-Z]{1}[0-9]{9}QA',
            'R[A-Z]{1}[0-9]{9}QA',
            'S[A-Z]{1}[0-9]{9}QA',
            'V[A-Z]{1}[0-9]{9}QA'
        ];
    }


    public function restrictCountries()
    {
        return [
            'qa',
            'ge',
            'us',
        ];
    }

    //TODO: Возвращается не валидное изображение капчи
    public function preheatCaptcha()
    {
        $this->get('https://qatarpost.qa/sites/captchaservlet', [
            RequestOptions::HEADERS => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Cache-Control' => 'max-age=0',
                'Connection' => 'keep-alive',
                'Host' => 'qatarpost.qa',
                'Referer' => 'https://qatarpost.qa/sites/captchaservlet',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'same-origin',
                'Upgrade-Insecure-Requests' => '1',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
                'sec-ch-ua' => '"Google Chrome";v="113", "Chromium";v="113", "Not-A.Brand";v="24"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
            ],
            RequestOptions::COOKIES => $this->jar]);
        $captchaImage = $this->get('https://qatarpost.qa/sites/captchaservlet', [
            RequestOptions::HEADERS => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Cache-Control' => 'max-age=0',
                'Connection' => 'keep-alive',
                'Host' => 'qatarpost.qa',
                'Referer' => 'https://qatarpost.qa/sites/captchaservlet',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'same-origin',
                'Upgrade-Insecure-Requests' => '1',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
                'sec-ch-ua' => '"Google Chrome";v="113", "Chromium";v="113", "Not-A.Brand";v="24"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
            ],
            RequestOptions::COOKIES => $this->jar]);

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