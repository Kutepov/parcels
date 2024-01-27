<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use Yii;
use yii\base\BaseObject;

class CorreoargentinoService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CaptchaPreheatInterface
{
    private $recaptchaKey = '6Lf3iSgTAAAAAFSSa4Ow3_1cKPA7LsUSI24tTtSE';

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

        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.correoargentino.com.ar/sites/all/modules/custom/ca_forms/api/wsFacade.php'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'action' => 'oidn',
                'id' => $trackNumber,
                'g_recaptcha_response' => $token
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($data);

        $result = new Parcel();

        foreach ($dom->find('tbody')->find('tr') as $checkpoint) {
            $dateStr = $checkpoint->find('*[data-title="Fecha:"]', 0)->text;
            $title = $checkpoint->find('*[data-title="Evento:"]', 0)->text;

            $dateTime = Carbon::parse($dateStr);
            $result->statuses[] = new Status([
                'title' => $title,
                'date' => $dateTime->timestamp,
                'location' => $checkpoint->find('*[data-title="Pais:"]', 0)->text . ' ' . $checkpoint->find('*[data-title="Oficina:"]', 0)->text,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' //RR693136659AR
        ];
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptcha([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://www.correoargentino.com.ar/formularios/oidn',
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