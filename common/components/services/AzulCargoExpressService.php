<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use yii;

class AzulCargoExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface, CountryRestrictionInterface, ManuallySelectedInterface
{
    public $id = 277;

    private $recaptchaKey = '6Lc4TXEUAAAAALK-gmkkX-nmgJG_HWjkRNx4oEbo';

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
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.azulcargoexpress.com.br/Rastreio/Rastreio/Rastrear'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
            RequestOptions::FORM_PARAMS => [
                'Awbs[]' => $trackNumber,
                'g-recaptcha-response' => $token,
                'BuscarRastreioPor' => 'awb'
            ],
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $result = new Parcel();

        $data = (new Dom())->loadStr($data);

        $weight = str_replace(',', '.', $data->find('.infoDetalhadas', 0)->find('.dados', 3)->text);
        $weightValue = $weight . ' Kg';
        $weight *= 1000;

        $result->weight = $weight;
        $result->weightValue = $weightValue;

        foreach ($data->find('.dadosDetalhados', 0)->find('.cardDetalhado') as $checkpoint) {
            $dateString = str_replace('/', '.', $checkpoint->find('.dataStatus', 0)->find('p', 0)->text);
            $timeString = $checkpoint->find('.dataStatus', 0)->find('p', 1)->text;

            $date = Carbon::parse($dateString . ' ' . $timeString);

            $result->statuses[] = new Status([
                'title' => html_entity_decode($checkpoint->find('.descricaoStatus', 0)->find('.tituloDescricao', 0)->text),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '\d{11}' //57796834850
        ];
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://www.azulcargoexpress.com.br/Rastreio/Rastreio?tipo=Courier',
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

    public function restrictCountries()
    {
        return ['br'];
    }
}