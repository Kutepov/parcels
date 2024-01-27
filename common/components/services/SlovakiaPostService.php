<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Yii;

class SlovakiaPostService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, CaptchaPreheatInterface
{
    public $id = 433;

    private $recaptchaKey = '6LeKoxsbAAAAABXmnYEMFL8ibgg2-v62IX9CKoTc';

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
        $getParams = [
            'q' => $trackNumber,
            'grct' => $token,
            'mmpc' => '1',
        ];
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://api.posta.sk/private/web/track?' . http_build_query($getParams)), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);

        if (isset($data['parcels'][0]['error'])) {
            return false;
        }

        $result = new Parcel();
        $result->weight = $data['parcels'][0]['weight'] * 1000;


        foreach ($data['parcels'][0]['events'] as $checkpoint) {
            $date = Carbon::parse(date('d.m.Y H:i:s', $checkpoint['at']/1000));

            $result->statuses[] = new Status([
                'title' => $checkpoint['desc']['sk'],
                'location' => $checkpoint['on']['city'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return $result;
    }


    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}\d{10}SK' //RF288549078
        ];
    }

    public function restrictCountries()
    {
        return ['cz', 'sk'];
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://tandt.posta.sk/',
        ]))) {
            return new Recaptcha([
                'token' => $token
            ]);
        }
        return null;
    }

    public function captchaLifeTime(): int
    {
        return 120;
    }

    public function recaptchaVersion()
    {
        return 3;
    }

    public function maxPreheatProcesses()
    {
        return 8;
    }

}