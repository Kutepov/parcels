<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\ExtraField;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use yii\base\BaseObject;
use Yii;

class TotalExpressBrService extends BaseService implements CountryRestrictionInterface, ValidateTrackNumberInterface, ExtraFieldsInterface, CaptchaPreheatInterface, AsyncTrackingInterface
{
    private $recaptchaKey = '6LePkvwUAAAAAJa1AIi8Tn1yG6hUS9RBIphP5M9Z';

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }


    public static function validateTrackNumber($trackNumber)
    {
        return true;
    }

    public function trackAsync($trackNumber, $extraFields = []): PromiseInterface
    {
        if (!($token = Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token, $extraFields);
    }

    public function track($trackNumber, $extraFields = [])
    {
        if (!($recaptcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $recaptcha->token, $extraFields)->wait();
    }

    private function request($trackNumber, $token, $extraFields)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://tracking.totalexpress.com.br/'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($extraFields, $token, $trackNumber, $jar) {

            $data = $response->getBody()->getContents();
            $dom = (new Dom())->loadStr($data);
            $csrf = $dom->find('meta[name="csrf-token"]')->getAttribute('content');

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://tracking.totalexpress.com.br/status-pedidos'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::FORM_PARAMS => [
                    '_token' => $csrf,
                    'nomeRazao' => $extraFields['extra_recipient_name'],
                    'cep' => $extraFields['extra_cep'],
                    'cpfCnpj' => $trackNumber,
                    'g-recaptcha-response' => $token,
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($data);
        $result = new Parcel();

        foreach ($dom->find('.col-sm-6', 0)->find('.linha-dados') as $index => $date) {
            $dateTime = $date->text ? Carbon::parse($date->text) : '';
            $title = $dom->find('.col-sm-6', 0)->find('.box-descricao', $index)->text;
            $result->statuses[] = new Status([
                'title' => $title,
                'date' => $dateTime instanceof Carbon ? $dateTime->timestamp : '',
                'dateVal' => $dateTime instanceof Carbon ? $dateTime->toDateString() : '',
                'timeVal' => $dateTime instanceof Carbon ? $dateTime->toTimeString('minute') : '',
            ]);
        }
        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return ['br'];
    }

    public function trackNumberRules(): array
    {
        return [
            '\d{3}.\d{3}.\d{3}-\d{2}' //937.713.800-06
        ];
    }

    public function extraFieldsTestValues(): array
    {
        return [
            'extra_recipient_name' => 'patricia',
            'extra_cep' => '98790060'
        ];
    }

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


    public function extraFields()
    {
        return [
            new ExtraField([
                'type' => ExtraField::TYPE_TEXT,
                'name' => 'extra_recipient_name',
                'placeholder' => 'Primeiro Nome / Razão social',
                'shortTitle' => 'Primeiro Nome / Razão social',
                'mask' => null,
                'field_regexp' => '.*?',
                'validateRegexp' => '^((?!\s*$).+){3,}',
                'delete_regexp' => null,
                'error' => \t('Заполните поле')
            ]),
            new ExtraField([
                'type' => ExtraField::TYPE_TEXT,
                'name' => 'extra_cep',
                'placeholder' => 'CEP',
                'shortTitle' => 'CEP',
                'mask' => null,
                'field_regexp' => '.*?',
                'validateRegexp' => '^((?!\s*$).+){3,}',
                'delete_regexp' => null,
                'error' => \t('Заполните поле')
            ])
        ];
    }

    public function extraFieldsTipApp()
    {
        return \t('Чтобы отследить посылку вам необходимо ввести дополнительную информацию.');
    }

    public function extraFieldsTip()
    {
        return \t('Чтобы отследить посылку вам необходимо ввести дополнительную информацию.');
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