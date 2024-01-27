<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use Symfony\Component\DomCrawler\Crawler;
use Yii;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class QualityPostService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, CaptchaPreheatInterface
{
    public $id = 447;

    private $recaptchaKey = '6LcCxLoaAAAAAG84vzOw5RctODbpOvz1tznI2gV5';

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }


    public function track($trackNumber)
    {
        if (!($recaptcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $recaptcha->token)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($token = \common\models\redis\Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token);
    }


    private function request($trackNumber, $token): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://tracking.qualitypost.com.mx/search.php'), $trackNumber, [
            RequestOptions::COOKIES => new CookieJar(),
            RequestOptions::HEADERS => [
                'apikey' => 'l7xx492b4e2b8682483c979222bdd33216cf'
            ],
            RequestOptions::FORM_PARAMS => [
                'action' => 'tracking',
                'id_rastreo' => $trackNumber,
                'g-recaptcha-response' => $token
            ]
        ]);
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' // RZ132616349MH
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $contents = $response->getBody()->getContents();
        $dom = new Crawler($contents);

        if (!$dom->filterXPath('//ul[@class="timeline"]')->count()) {
            return false;
        }

        $result = new Parcel();

        $dom->filterXPath('//div[@class="content-historymap col-md-8"]//ul//li')->each(function (Crawler $checkpoint) use (&$result) {
            $data = explode(' - ', $checkpoint->text());
            if (count($data) === 3) {
                $dateTime = Carbon::parse(str_replace('/', '-', $data[1]));

                $result->statuses[] = new Status([
                    'title' => $data[2],
                    'location' => $data[0],
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                ]);
            }
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'us',
            'mx',
        ];
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://tracking.qualitypost.com.mx/search.php',
        ]))) {
            return new Recaptcha([
                'token' => $token
            ]);
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