<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\PreheatedCaptcha;
use common\models\redis\Recaptcha;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use PHPHtmlParser\Dom;

class TotalexpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface
{
    /** @var string */
    public $cpf;

    /** @var string */
    public $cep;

    private $recaptchaKey = '6LePkvwUAAAAAJa1AIi8Tn1yG6hUS9RBIphP5M9Z';

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
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
        return false;
    }

    public function parseResponse($response, $trackNumber)
    {
        return false;
    }

    /**
     * @return Recaptcha|PreheatedCaptcha|null
     */
    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://tracking.totalexpress.com.br/',
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

    public function trackNumberRules(): array
    {
        return [''];
    }
}