<?php namespace common\components\services;

use common\components\AntiCaptcha\Client;
use common\components\AntiCaptcha\Tasks\Letters;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;
use Yii;

class AeroflotService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, CaptchaPreheatInterface
{
    public $id = 453;

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
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.aeroflot.ru/personal/cargo_tracking?_preferredLanguage=ru'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
            RequestOptions::HEADERS => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control' => 'max-age=0',
                'Connection' => 'keep-alive',
                'Host' => 'www.aeroflot.ru',
                'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="97", "Chromium";v="97"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.99 Safari/537.36',
            ]
        ], function (ResponseInterface $response) use ($trackNumber, $token, $jar) {
            $dom = new Crawler($response->getBody()->getContents());
            $csrf = $dom->filterXPath('//input[@id="id_csrf_token"]')->attr('value');

            //FIXME: Возвращает 403, непонятно почему
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.aeroflot.ru/personal/cargo_tracking?_preferredLanguage=ru'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Cache-Control' => 'max-age=0',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'multipart/form-data; boundary=----WebKitFormBoundary' . mb_substr(md5($trackNumber), 0, 16),
                    'Host' => 'www.aeroflot.ru',
                    'Origin' => 'https://www.aeroflot.ru',
                    'Referer' => 'https://www.aeroflot.ru/personal/cargo_tracking?_preferredLanguage=ru',
                    'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="97", "Chromium";v="97"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'same-origin',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.99 Safari/537.36',
                ],
                RequestOptions::FORM_PARAMS => [
                    'csrf_token' => $csrf,
                    'awb_0' => '555',
                    'awb_1' => '31506101',
                    'captcha_text' => $token,
                    'submit_awb' => 'Подождите…'
                ]
            ]);
        });
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{3}-[0-9]{8}', //555-31506101
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        return false;
    }

    public function restrictCountries()
    {
        return [
            'ru',
            'us',
            'de',
            'kz',
            'uk'
        ];
    }

    public function preheatCaptcha()
    {
        $captchaImage = $this->get('https://www.aeroflot.ru/personal/_captcha/captcha.jpg', [
            RequestOptions::HEADERS => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control' => 'max-age=0',
                'Connection' => 'keep-alive',
                'Host' => 'www.aeroflot.ru',
                'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="97", "Chromium";v="97"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.99 Safari/537.36',
            ]
        ]);

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