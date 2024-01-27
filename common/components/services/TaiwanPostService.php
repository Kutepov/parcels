<?php namespace common\components\services;

use common\components\AntiCaptcha\Client;
use common\components\AntiCaptcha\Tasks\Numbers;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RequestOptions;
use yii;
use Carbon\Carbon;

class TaiwanPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface
{
    public $id = 192;
    public $captcha = true;

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function track($trackNumber)
    {
        $jar = new CookieJar();

        $this->getWithProxy('https://postserv.post.gov.tw/pstmail/main_mail.html', [
            RequestOptions::COOKIES => $jar
        ]);

        $uuid = $this->getUUID();

        $captcha = $this->getWithProxy('https://postserv.post.gov.tw/pstmail/jcaptcha?uuid=' . $uuid, [
            RequestOptions::COOKIES => $jar
        ]);

        $captcha = $this->antiCaptchaService->resolve(new Numbers([
            'body' => base64_encode($captcha),
            'CapMonsterModule' => 'ZennoLab.universal'
        ]));

        $result = $this->postWithProxy('https://postserv.post.gov.tw/pstmail/EsoafDispatcher', [
            RequestOptions::COOKIES => $jar,
            RequestOptions::BODY => '{"header":{"InputVOClass":"com.systex.jbranch.app.server.post.vo.EB500200InputVO","TxnCode":"EB500200","BizCode":"query","StampTime":true,"SupvPwd":"","TXN_DATA":{},"SupvID":"","CustID":"","REQUEST_ID":"","ClientTransaction":true,"DevMode":false,"SectionID":"esoaf"},"body":{"MAILNO":"' . $trackNumber . '","uuid":"' . $uuid . '","captcha":"' . $captcha . '","pageCount":10}}'
        ]);

        $data = json_decode(trim($result));

        if (!($data = $data[0]->body->list ?? false)) {
            return false;
        }

        $result = new Parcel();

        foreach ($data as $eventsList) {
            foreach ($eventsList->event as $event) {
                $date = str_replace('/', '-', $event->proc_datetime);

                $date = Carbon::parse($date);

                $result->statuses[] = new Status([
                    'title' => $event->status,
                    'location' => $event->dest,
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                ]);
            }
        }

        return $result;
    }

    function getUUID()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}TW'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}\d{9}TW'
        ];
    }
}