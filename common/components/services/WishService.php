<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\AntiCaptcha\Tasks\Numbers;
use common\components\services\events\TrackingCompletedEvent;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\PreheatedCaptcha;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use yii;

class WishService extends BaseService implements ValidateTrackNumberInterface, CaptchaPreheatInterface, AsyncTrackingInterface
{
    public $id = 190;

    private $requestAttempt = 1;
    private $maxRequestAttempts = 5;
    private $isAsyncRequest = false;

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function track($trackNumber)
    {
        if (!($solvedCaptcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $solvedCaptcha)->wait();
    }

    private function request($trackNumber, PreheatedCaptcha $preheatedCaptcha)
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.wishpost.cn/api/tracking/search'), $trackNumber, [
            'retry_on_status' => [500, 502, 503, 506, 403, 429],
            RequestOptions::COOKIES => $preheatedCaptcha->cookies,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json'
            ],
            RequestOptions::JSON => [
                'params_num' => 1,
                'api_name' => 'tracking/search',
                'captcha' => $preheatedCaptcha->answer,
                'ids[]' => [
                    $trackNumber
                ],
            ]
        ], function (ResponseInterface $response) use ($trackNumber, $preheatedCaptcha) {
            $data = json_decode($response->getBody()->getContents());
            $response->getBody()->seek(0);

            if (isset($data->msg) && (stristr($data->msg, 'This API requires correct captcha verification') || stristr($data->msg, '此API要求正确的验证码'))) {
                if ($this->requestAttempt < $this->maxRequestAttempts) {
                    $this->requestAttempt++;
                    if ($this->isAsyncRequest) {
                        return $this->trackAsync($trackNumber);
                    }
                    else {
                        return $this->track($trackNumber);
                    }
                }
                else {
                    throw new \Exception();
                }
            }
            else {
                $data = $this->parseResponse($response, $trackNumber);

                Yii::$app->trigger(self::EVENT_TRACKING_COMPLETED, new TrackingCompletedEvent([
                    'courierId' => $this->id,
                    'trackNumber' => $trackNumber,
                    'parcelInfo' => $data,
                    'success' => $data !== false
                ]));

                return $data;
            }
        });
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($preheatedCaptcha = \common\models\redis\PreheatedCaptcha::findForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        $this->isAsyncRequest = true;

        return $this->request($trackNumber, $preheatedCaptcha);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents());

        if ($data = $data->data->{$trackNumber}) {
            $result = new Parcel([
                'destinationAddress' => implode(',', array_filter([$data->receiver_country ?? null, $data->reveiver_city ?? null])),
                'departureAddress' => implode(',', array_filter([$data->sender_country ?? null, $data->sender_city ?? null])),
            ]);

            foreach ($data->checkpoints as $checkpoint) {
                $date = $checkpoint->date;
                $title = $checkpoint->status_desc;
                if (preg_match('#Cancelled at (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})#siu', $checkpoint->status_desc, $dateMatch)) {
                    $date = $dateMatch[1];
                    $title = 'Cancelled. Carrier declared cancelling the order';
                }
                $date = Carbon::parse($date);
                $result->statuses[] = new Status([
                    'title' => $title,
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                    'location' => implode(',', array_filter([$checkpoint->country_code ?? null, $checkpoint->city ?? null])) ?: $checkpoint->remark
                ]);

                if (isset($checkpoint->weight) && $checkpoint->weight > 0) {
                    $result->weight = $checkpoint->weight * 1000;
                }
            }

            return $result;
        }

        return false;
    }

    public function preheatCaptcha()
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.wishpost.cn/get-new-captcha?version=' . rawurlencode(date('r') . ' (Москва, стандартное время)')), null,
            [
                RequestOptions::COOKIES => $jar = new CookieJar()
            ], function (ResponseInterface $response) use ($jar) {
                $image = $response->getBody()->getContents();
                $captcha = $this->antiCaptchaService->resolve(new Numbers([
                    'body' => base64_encode($image)
                ]));

                return new PreheatedCaptcha([
                    'answer' => $captcha,
                    'cookies' => $jar
                ]);
            })->wait();
    }

    public function captchaLifeTime(): int
    {
        return 60 * 10;
    }

    public function recaptchaVersion()
    {
        return null;
    }

    public function trackNumberRules(): array
    {
        return [
            'WI\d{11}SH',
            'WI\d{12}[A-Z]{3}',
            'SYWH\d{9}',
            'WOSP\d{12}[A-Z]{3}'//ESP$
        ];
    }

    public function maxPreheatProcesses()
    {
        return 24;
    }
}