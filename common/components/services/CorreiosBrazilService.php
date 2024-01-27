<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\AntiCaptcha\Tasks\Letters;
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
use yii\helpers\Json;
use yii;

class CorreiosBrazilService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface
{
    public $id = 107;

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

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($preheatedCaptcha = \common\models\redis\PreheatedCaptcha::findForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        $this->isAsyncRequest = true;

        return $this->request($trackNumber, $preheatedCaptcha);
    }

    private function request($trackNumber, PreheatedCaptcha $preheatedCaptcha)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://rastreamento.correios.com.br/app/resultado.php'), $trackNumber, [
            RequestOptions::QUERY => [
                'objeto' => $trackNumber,
                'captcha' => $preheatedCaptcha->answer,
                'mqs' => 'S'
            ],
            RequestOptions::COOKIES => $preheatedCaptcha->cookies
        ], function (ResponseInterface $response) use ($trackNumber, $preheatedCaptcha) {
            $data = json_decode($response->getBody()->getContents());
            $response->getBody()->seek(0);

            if (isset($data->mensagem) && (stristr($data->mensagem, 'Captcha inválido'))) {
                if ($this->requestAttempt < $this->maxRequestAttempts) {
                    $this->requestAttempt++;
                    if ($this->isAsyncRequest) {
                        return $this->trackAsync($trackNumber);
                    } else {
                        return $this->track($trackNumber);
                    }
                } else {
                    throw new \Exception();
                }
            } else {
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

    public function parseResponse($response, $trackNumber)
    {
        $response = Json::decode($response->getBody()->getContents());

        $result = new Parcel();

        if (!isset($response['eventos'])) {
            return false;
        }

        foreach ($response['eventos'] as $checkpoint) {
            if (is_array($checkpoint['dtHrCriado'])) {
                $date = Carbon::parse($checkpoint['dtHrCriado']['date'], $checkpoint['dtHrCriado']['timezone']);
            }
            else {
                $date = Carbon::parse(str_replace('/', '.', substr($checkpoint['dtHrCriado'], 0, -3)));
            }
            $result->statuses[] = new Status([
                'title' => $checkpoint['descricao'],
                'location' => implode(' - ', array_filter(
                        [
                            $checkpoint['unidade']['endereco']['cidade'],
                            $checkpoint['unidade']['endereco']['uf']
                        ])
                ),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        if (count($result->statuses)) {
            return $result;
        }

        return false;
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}LT',
            'C[A-Z]{1}[0-9]{9}LT',
            'E[A-Z]{1}[0-9]{9}LT',
            'L[A-Z]{1}[0-9]{9}LT',
            'M[A-Z]{1}[0-9]{9}LT',
            'R[A-Z]{1}[0-9]{9}LT',
            'S[A-Z]{1}[0-9]{9}LT',
            'U[A-Z]{1}[0-9]{9}LT',
            'V[A-Z]{1}[0-9]{9}LT',
            '[A-Z]{2}[0-9]{9}BR',
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

    public function preheatCaptcha()
    {
        $t = rand(10000000, 99999999) . rand(10000000, 99999999);

        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://rastreamento.correios.com.br/core/securimage/securimage_show.php?0.' . $t), null,
            [
                RequestOptions::COOKIES => $jar = new CookieJar()
            ], function (ResponseInterface $response) use ($jar) {
                $image = $response->getBody()->getContents();
                $captcha = $this->antiCaptchaService->resolve(new Letters([
                    'body' => base64_encode($image),
                    'CapMonsterModule' => 'ZennoLab.universal'
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

    public function maxPreheatProcesses()
    {
        return 8;
    }
}