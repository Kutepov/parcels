<?php namespace common\components\services;

use Carbon\Carbon;
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
use yii;

class EtsExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, BatchTrackInterface, CaptchaPreheatInterface
{
    public $id = 77;

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($captcha = $this->preheatCaptcha())) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $captcha['code'], $captcha['jar']);
    }

    public function track($trackNumber)
    {
        if (!($captcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $captcha['code'], $captcha['jar'])->wait();
    }

    public function request($trackNumbers, $code, $jar)
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://www.ets-express.com/Home/Indexru/guiji_ru.html'), $trackNumbers, [
            RequestOptions::COOKIES => $jar,
            RequestOptions::FORM_PARAMS => [
                'danhao' => is_array($trackNumbers) ? implode("\r\n", $trackNumbers) : $trackNumbers,
                'yanzhengma' => $code
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $response = $response->getBody()->getContents();
        $dom = (new Dom())->loadStr($response);

        $trackDivs = $dom->find('.content', 0)->find('.text');

        foreach ($trackDivs as $track) {
            $trackNumberDiv = $track->find('ul', 0)->find('li', 0)->text;
            $currentTrackNumber = '';
            preg_match('/:(.*?)&/', $trackNumberDiv, $currentTrackNumber);

            if ($currentTrackNumber[1] === $trackNumber) {
                if ($track->find('ul', 0)->find('li')->count() <= 1) {
                    return false;
                }

                $result = new Parcel();

                foreach ($track->find('ul', 0)->find('li') as $index => $checkpoint) {
                    if ($checkpoint->find('span')->count() > 1 && $index !== 0) {
                        $dateTime = Carbon::parse($checkpoint->find('span', 0)->text);
                        $result->statuses[] = new Status([
                            'title' => $checkpoint->find('span', 2)->text,
                            'date' => $dateTime->timestamp,
                            'dateVal' => $dateTime->toDateString(),
                            'timeVal' => $dateTime->toTimeString('minute'),
                        ]);
                    }
                }
            }

        }

        return $result;
    }

    public function preheatCaptcha()
    {
        $this->getWithProxy('http://www.ets-express.com/Home/Indexru/guiji_ru.html', [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ]);

        $captchaImage = $this->getWithProxy('http://www.ets-express.com/admin.php/Home/Yanzhengma/index', [
            RequestOptions::COOKIES => $jar
        ], true);

        if (!($captcha = $this->antiCaptchaService->resolve(new Letters([
            'body' => base64_encode($captchaImage->getBody()),
            'CapMonsterModule' => 'ZennoLab.universal'
        ])))) {
            return false;
        }

        return ['code' => $captcha, 'jar' => $jar];
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 10;
    }


    public function captchaLifeTime(): int
    {
        return 120;
    }


    public function recaptchaVersion()
    {
        return 1;
    }

    public function maxPreheatProcesses()
    {
        return 8;
    }

    public function trackNumberRules(): array
    {
        return [
            'ETS[A-Z]{2}\d{10}YQ' //ETSSD1027130993YQ
        ];
    }

}