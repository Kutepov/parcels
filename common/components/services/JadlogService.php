<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use yii;

class JadlogService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface, CountryRestrictionInterface
{
    public $id = 306;
    private $mainData;

    private $recapthaKey = '6LdKnBQTAAAAAAAU-lKQVVWhnM45n72W069eDkje';

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($recaptcha = $this->preheatCaptcha())) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $recaptcha, $recaptcha);
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recapthaKey,
            'websiteURL' => 'https://www.jadlog.com.br/siteInstitucional/tracking.jad',
        ]))) {
            return new Recaptcha([
                'token' => $token
            ]);
        }

        return null;
    }

    private function request($trackNumber, $token, $tokenV2)
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.jadlog.com.br/siteInstitucional/tracking.jad'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'javax.faces.partial.ajax' => 'true',
                'javax.faces.source' => 'j_idt41',
                'javax.faces.partial.execute' => '@all',
                'javax.faces.partial.render' => 'form_tracking',
                'form_tracking:j_idt41' => 'form_tracking:j_idt41',
                'form_tracking' => 'form_tracking',
                'cte' => $trackNumber,
                'captchaVersao' => 'V2',
                'g-recaptcha-response' => $tokenV2,
            ],
        ], function (ResponseInterface $response) use ($trackNumber, $token) {

            $this->mainData = $response->getBody()->getContents();
            return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.jadlog.com.br/siteInstitucional/tracking_dev.jad?cte='.$trackNumber.'&g-recaptcha-response='.$token), $trackNumber);

        });
    }

    public function track($trackNumber)
    {
        if (!($recaptcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $recaptcha->token, $recaptcha->token)->wait();
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $result = new Parcel();

        $mainData = (new Dom())->loadStr(trim($this->clearString($this->mainData)));
        $data = (new Dom())->loadStr($data);

        $result->recipient = $mainData->find('.fasesOdd', 0)->find('td', 4)->text;

        foreach ($data->find('#j_idt2_data')->find('tr') as $checkpoint) {
            $date = Carbon::parse(str_replace('/', '.', trim($checkpoint->find('td', 0)->find('span', 0)->text)));

            $result->statuses[] = new Status([
                'title' => $checkpoint->find('td', 2)->find('span', 0)->text,
                'location' => $checkpoint->find('td', 3)->find('span', 0)->text,
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    private function clearString(string $data)
    {
        $start = stripos($data, '<form id="form_tracking');
        $length = stripos($data, '</form>');
        return substr($data, $start, $length-$start);
    }

    public function trackNumberRules(): array
    {
        return [
            '\d{14}' //08065101199372
        ];
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