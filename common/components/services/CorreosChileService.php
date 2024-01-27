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
use Psr\Http\Message\ResponseInterface;

class CorreosChileService extends BaseService implements ValidateTrackNumberInterface, CountryRestrictionInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $disableAutoUpdate = true;
    public $id = 166;
    private $url = 'https://correos.cl';
    private $recaptchaKey = '6LfY05MaAAAAACjwyL5NdsDA7JgK0XSVaar6IIm0';

    /** @var Client */
    private $antiCaptchaService;


    public function __construct($data = null)
    {
        $this->antiCaptchaService = \Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function track($trackNumber)
    {
        if (!($recaptcha = $this->preheatCaptcha())) {
            return false;
        }

        return $this->request($trackNumber, $recaptcha->token)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}CL',
            'C[A-Z]{1}[0-9]{9}CL',
            'E[A-Z]{1}[0-9]{9}CL',
            'L[A-Z]{1}[0-9]{9}CL',
            'R[A-Z]{1}[0-9]{9}CL',
            'S[A-Z]{1}[0-9]{9}CL',
            'U[A-Z]{1}[0-9]{9}CL',
            'V[A-Z]{1}[0-9]{9}CL',
            '[A-Z]{3}[0-9]{10}',
            '[A-Z]{3}[0-9]{8}',
            '107[0-9]{23}',
            '128[0-9]{23}',
            '132[0-9]{23}'
        ];
    }

    /**
     * @return array
     */
    public function restrictCountries()
    {
        return ['cl'];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        if (!($token = Recaptcha::findTokenForProvider($this->id))) {
            throw new BadPreheatedCaptchaException();
        }

        return $this->request($trackNumber, $token);
    }

    private function request($trackNumber, $token)
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.correos.cl/web/guest/home#0'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
            RequestOptions::HEADERS => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'Accept-Encoding' => 'gzip, deflate',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control' => 'max-age=0',
                'Connection' => 'keep-alive',
                'Host' => 'www.correos.cl',
                'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="91", "Chromium";v="91"',
                'sec-ch-ua-mobile' => '?0',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.106 Safari/537.36',
            ],
            RequestOptions::TIMEOUT => 30,
            RequestOptions::CONNECT_TIMEOUT => 30
        ], function (ResponseInterface $response) use ($jar, $trackNumber, $token) {
            $authToken = $this->getAuthToken($response->getBody()->getContents());

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.correos.cl/web/guest/seguimiento-en-linea?p_p_id=cl_cch_seguimiento_portlet_seguimientoenlineaportlet_INSTANCE_rsbcMueFRL4k&p_p_lifecycle=2&p_p_state=normal&p_p_mode=view&p_p_resource_id=cl_cch_seguimiento_portlet_seguimientoresurcecommand&p_p_cacheability=cacheLevelPage&_cl_cch_seguimiento_portlet_seguimientoenlineaportlet_INSTANCE_rsbcMueFRL4k_cmd=cmd_resource_get_seguimientos'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::FORM_PARAMS => [
                    '_cl_cch_seguimiento_portlet_seguimientoenlineaportlet_INSTANCE_rsbcMueFRL4k_param_nro_seguimiento' => $trackNumber,
                    '_cl_cch_seguimiento_portlet_seguimientoenlineaportlet_INSTANCE_rsbcMueFRL4k_token' => $token,
                    'p_auth' => $authToken,
                ],
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json, text/javascript, */*; q=0.01',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Host' => 'www.correos.cl',
                    'Origin' => 'https://www.correos.cl',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'sec-ch-ua' => '" Not;A Brand";v="99", "Google Chrome";v="91", "Chromium";v="91"',
                    'sec-ch-ua-mobile' => '?0',
                    'Sec-Fetch-Dest' => 'empty',
                    'Sec-Fetch-Mode' => 'cors',
                    'Sec-Fetch-Site' => 'same-origin',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.106 Safari/537.36',
                    'Referer' => 'https://www.correos.cl/web/guest/seguimiento-en-linea?codigos=' . $trackNumber,
                ],
            ]);
        });
    }

    private function getAuthToken($data)
    {
        preg_match("#Liferay\.authToken = '(.*?)';#siu", $data, $m);
        return $m[1];
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);
        $data = json_decode($data['seguimiento'], true);

        if (!empty($data)) {
            $statuses = [];

            foreach ($data['historial'] as $item) {

                $date = Carbon::parse($item['FechaDate']);

                $statuses[] = new Status([
                    'title' => $item['Estado'],
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                    'location' => $item['Oficina'],
                ]);
            }

            return new Parcel([
                'statuses' => $statuses
            ]);
        }

        return false;
    }

    public function preheatCaptcha()
    {
        if ($token = $this->antiCaptchaService->resolve(new \common\components\AntiCaptcha\Tasks\ReCaptchaV3([
            'websiteKey' => $this->recaptchaKey,
            'websiteURL' => 'https://www.correos.cl/web/guest',
            'pageAction' => 'submit',
        ]))) {
            return new Recaptcha([
                'token' => $token
            ]);
        }

        return null;
    }

}