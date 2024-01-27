<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\AntiCaptcha\Tasks\Letters;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class FlytExpressService extends BaseService implements ValidateTrackNumberInterface
{
    public $captcha = true;
    public $id = 12;
    protected $url = 'http://www.flytexpress.com';

    /** @var Client */
    private $antiCaptchaService;


    public function __construct($data = null)
    {
        $this->antiCaptchaService = \Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function track($trackNumber)
    {
        $request = $this->getWithProxy($this->url . '/Home/LogisticsTracking', [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], true);
        $proxyAddr = $request->getHeader('Proxy-Addr')[0];

        /** @var ResponseInterface $captchaImage */
        $captchaImage = $this->getWithProxy($this->url . '/Home/GetValidateCode', [
            RequestOptions::COOKIES => $jar,
            RequestOptions::HEADERS => [
                'Proxy-Addr' => $proxyAddr,
            ]
        ], true);


        if ($captchaImage = $captchaImage->getBody()) {
            $captchaImage = base64_encode($captchaImage);
        }
        else {
            return false;
        }

        $captcha = $this->antiCaptchaService->resolve(new Letters([
            'body' => $captchaImage,
            'CapMonsterModule' => 'ZennoLab.universal'
        ]));

        if ($captcha) {
            $request = $this->postWithProxy($this->url . '/Home/GetTrackingInformation', [
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'Proxy-Addr' => $proxyAddr,
                    'Pragma' => 'no-cache',
                    'Referer' => 'http://www.flytexpress.com/En/Home/LogisticsTracking',
                    'Cache-Control' => 'no-cache'
                ],
                RequestOptions::JSON => [
                    'orderIds' => [
                        $trackNumber
                    ],
                    'validationCode' => $captcha
                ]
            ]);

            $data = json_decode($request, true)['datas'][0];
            if (!empty($data)) {
                $statuses = [];

                foreach ($data['tackingInfos'] as $item) {
                    if (stristr($item['trackingInformation'], 'Tracking information is not found')) {
                        return false;
                    }

                    $date = Carbon::parse(str_replace('/', '-', $item['trackingTime']));
                    $statuses[] = new Status([
                        'title' => $item['trackingInformation'],
                        'date' => $date->timestamp,
                        'dateVal' => $date->toDateString(),
                        'timeVal' => $date->toTimeString('minute'),
                        'location' => trim($item['trackingLocation'], ' ,'),
                    ]);
                }

                return new Parcel([
                    'departureCountry' => empty($data['provenance']) ? '' : $data['provenance'],
                    'destinationCountry' => empty($data['destination']) ? '' : $data['destination'],
                    'statuses' => $statuses
                ]);
            }
        }

        return false;
    }


    public function trackNumberRules(): array
    {
        return [
            'FTLVU[0-9]{8}',
            'A[0-9]{11}[A-Z0-9]{4}',
            'F[0-9]{11}[A-Z0-9]{4}',
            '(A|F)[0-9]{12,13}[A-Z0-9]{2,3}',
            'CTAFT[0-9]{10}YQ',
            'FTLVU[0-9]{8}',
            'UT[0-9]{9}TH'
        ];
    }
}
