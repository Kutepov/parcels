<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use common\components\AntiCaptcha\Tasks\Numbers;

class ArasKargoService extends BaseService implements CountryRestrictionInterface, ValidateTrackNumberInterface, CaptchaPreheatInterface, AsyncTrackingInterface
{
    public $id = 256;

    private $imageCaptcha;

    public function restrictCountries()
    {
        return [
            'tr'
        ];
    }

    /** @var Client */
    private $antiCaptchaService;


    public function __construct($data = null)
    {
        $this->antiCaptchaService = \Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new Numbers([
            'body' => base64_encode($this->imageCaptcha),
            'CapMonsterModule' => 'ZennoLab.universal'
        ]))) {
            return $token;
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

    public function track($trackNumber)
    {
        return $this->request($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->request($trackNumber);
    }

    public function request($trackNumber)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://araskargo.com.tr/tr/cargo_tracking_detail.aspx?query=1&querydetail=2&ref_no=&seri_no=&irs_no=&kargo_takip_no=' . $trackNumber . '&atf_no=&customer_code=&integration_code='), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            $sid = $jar->getCookieByName('ASP.NET_SessionId')->getValue();


            $this->imageCaptcha = $this->getWithProxy( 'https://araskargo.com.tr/tr/get_captcha.aspx?cid=' . $sid . '&random=' . rand(100000, 999999), [
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8'
                ]
            ]);

            if (!($captcha = $this->preheatCaptcha())) {
                return false;
            }

            $dom = new Dom();
            $dom->loadStr($response->getBody()->getContents());

            if ($form = $dom->find('form', 0)) {
                $action = 'https://araskargo.com.tr/tr' . substr($form->getAttribute('action'), 1);
                $inputs = [];

                foreach ($form->find('input') as $input) {
                    $inputs[$input->getAttribute('name')] = $input->getAttribute('value');
                }

                $inputs['form_captcha'] = $captcha;
                $inputs['ButtonCode.x'] = rand(1, 22);
                $inputs['ButtonCode.y'] = rand(1, 17);

                return $this->sendAsyncRequestWithProxy(new Request('POST', $action), $trackNumber, [
                    RequestOptions::FORM_PARAMS => $inputs,
                    RequestOptions::COOKIES => $jar,
                    RequestOptions::HEADERS => [
                        'Referer' => 'https://araskargo.com.tr/tr/cargo_tracking_detail.aspx?query=1&querydetail=2&ref_no=&seri_no=&irs_no=&kargo_takip_no=' . $trackNumber . '&atf_no=&customer_code=&integration_code='
                    ]
                ]);
            }

            return false;
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Dom();
        $content = $response->getBody()->getContents();
        $dom->loadStr($content);

        $result = new Parcel([
            'sender' => ($sender = $dom->find('#gonderici', 0)) ? $sender->text : null,
            'recipient' => ($recipient = $dom->find('#alici', 0)) ? $recipient->text : null,
            'departureAddress' => ($dep = $dom->find('#cikis_subesi', 0)) ? strip_tags($dep->innerHtml) : null,
            'destinationAddress' => ($des = $dom->find('#varis_subesi', 0)) ? strip_tags($des->innerHtml) : null,
        ]);

        if (($tables = $dom->find('.table_cargo2'))->count()) {
            foreach ($tables as $table) {
                foreach ($table->find('tr') as $checkpoint) {
                    if ($checkpoint->find('td')->count() == 3 && !$checkpoint->getAttribute('class')) {
                        $date = Carbon::parse(trim(strip_tags($checkpoint->find('td', 0)->innerHtml)));
                        $result->statuses[] = new Status([
                            'title' => trim(strip_tags($checkpoint->find('td', 2)->innerHtml)),
                            'location' => trim(strip_tags($checkpoint->find('td', 1)->innerHtml)),
                            'date' => $date->timestamp,
                            'dateVal' => $date->toDateString(),
                            'timeVal' => $date->toTimeString('minute'),
                        ]);
                    }
                }
            }
        }

        if (($deliveredTable = $dom->find('#TableStep2CargoDetails'))->count()) {
            foreach ($deliveredTable->find('table tr') as $checkpoint) {
                $date = Carbon::parse(trim(strip_tags($checkpoint->find('#teslim_tarihi2')->innerHtml . ' ' . $checkpoint->find('#teslim_saati2')->innerHtml)));
                $result->statuses[] = new Status([
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                    'location' => strip_tags($checkpoint->find('#teslim_alan2')->innerHtml),
                    'title' => strip_tags($checkpoint->find('#durum2')->innerHtml)
                ]);
            }
        }

        if (preg_match_all('#<td>[\s\t]+<span class="span_cargo_headline_2">(Ödeme Tipi|Kargo Desisi|Kargo Adedi|Teslim Süresi|Kargo Tipi)</span>[\s\t]+<span id=".*?">(.*?)</span>[\s\t]+</td>#iu', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $k => $v) {
                $result->extraInfo[trim($v[1])] = trim($v[2]);
            }
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [];
    }
}