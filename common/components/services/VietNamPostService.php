<?php

namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\AntiCaptcha\Tasks\Letters;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\RequestOptions;
use Yii;

class VietNamPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface
{

    public $captcha = true;

    public $id = 120;

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }


    public function track($trackNumber)
    {
        $jar = new \GuzzleHttp\Cookie\CookieJar;

        $request = $this->getWithProxy('http://www.vnpost.vn/en-us/dinh-vi/buu-pham', [
            'cookies' => $jar,
            RequestOptions::TIMEOUT => 60,
            RequestOptions::QUERY => [
                'key' => $trackNumber
            ]
        ], true);

        $content = $request->getBody()->getContents();

        preg_match('/__VIEWSTATE.*?value="(.*?)"/is', $content, $__VIEWSTATE);
        preg_match('/__VIEWSTATEGENERATOR.*?value="(.*?)"/is', $content, $__VIEWSTATEGENERATOR);
        preg_match('/__EVENTVALIDATION.*?value="(.*?)"/is', $content, $__EVENTVALIDATION);
        preg_match('/Catpchar\.aspx\?t=(.*?)"/is', $content, $urlCatpchar);

        $captchaImage = $this->getWithProxy('http://www.vnpost.vn/desktopmodules/vnp_webapi/Catpchar.aspx', [
            'cookies' => $jar,
            RequestOptions::QUERY => [
                't' => $urlCatpchar[1]
            ]
        ]);

        if (!empty($captchaImage)) {
            $captcha = base64_encode($captchaImage);
        }
        else {
            return false;
        }

        $captcha = strtolower($this->antiCaptchaService->resolve(new Letters([
            'body' => $captcha,
            'case' => true,
        ])));

        if ($captcha) {
            $request = $this->postWithProxy('http://www.vnpost.vn/en-us/dinh-vi/xac-thuc?url=/en-us/dinh-vi/buu-pham?key=' . $trackNumber, [
                'cookies' => $jar,
                RequestOptions::TIMEOUT => 60,
                RequestOptions::FORM_PARAMS => [
                    'StylesheetManager_TSSM' => '',
                    'ScriptManager_TSM' => '',
                    '__EVENTTARGET' => 'dnn$ctr808$View$uc$btnCheckCaptchar',
                    '__EVENTARGUMENT' => '',
                    '__VIEWSTATE' => $__VIEWSTATE[1],
                    '__VIEWSTATEGENERATOR' => $__VIEWSTATEGENERATOR[1],
                    '__VIEWSTATEENCRYPTED' => '',
                    '__EVENTVALIDATION' => $__EVENTVALIDATION[1],
                    'dnn$ctr808$View$uc$ucCaptchar$txtCaptchar' => $captcha,
                    'ScrollTop' => '',
                    '__dnnVariable' => ''
                ]
            ], false);

            preg_match('/<table class="table-tracking-info">(.*?)<\/table>/is', $request, $matchesInfo);

            $departureCountry = [];
            $destinationCountry = [];

            if (!empty($matchesInfo[1])) {
                preg_match_all('/<tr.*?>(.*?)<\/tr>/is', $matchesInfo[1], $acceptanceAndDelivery);
                preg_match_all('/<td.*?>(.*?)<\/td>/is', $acceptanceAndDelivery[1][0], $acceptanceAndDelivery);

                preg_match('/[A-Z]{2}/sm', $acceptanceAndDelivery[1][0], $departureCountry);
                preg_match('/[A-Z]{2}/sm', $acceptanceAndDelivery[1][1], $destinationCountry);
            }

            preg_match('/package-weight.*?([0-9]+)</is', $request, $weight);

            preg_match('/timeline-list-item">(.*?)<\/div>/is', $request, $matches);

            if (!empty($matches[1])) {
                $statuses = [];

                preg_match_all('/<li.*?>(.*?)<\/li>/is', $matches[1], $items);

                foreach ($items[1] as $item) {

                    preg_match_all('/">(.*?)</is', $item, $data);

                    $dateTime = Carbon::parse(str_replace('/', '-', trim($data[1][0])));
                    $location = $data[1][2];

                    if (preg_match('/\((.*?)\).*?\(.*?:(.*?)\)/is', $data[1][1])) {
                        preg_match('/\((.*?)\).*?\(.*?:(.*?)\)/is', $data[1][1], $data);

                        $title = $data[1];
                        $location = $data[2];

                    }
                    elseif (preg_match('/\((.*?)\)/is', $data[1][1])) {
                        if (preg_match('/[0-9]{6}:/is', $data[1][1])) {
                            preg_match('/^(.*?)\(.*?:(.*?)\)/is', $data[1][1], $data);
                            $title = $data[1];
                            $location = $data[2];
                        }
                        else {
                            preg_match('/\((.*?)\)/is', $data[1][1], $data);
                            $title = $data[1];
                        }
                    }
                    else {
                        $title = $data[1][1];
                    }

                    $statuses[] = new Status([
                        'title' => trim($title),
                        'date' => $dateTime->timestamp,
                        'dateVal' => $dateTime->toDateString(),
                        'timeVal' => $dateTime->toTimeString('minute'),
                        'location' => trim($location)
                    ]);
                }

                return new Parcel([
                    'departureCountryCode' => $departureCountry[0],
                    'destinationCountryCode' => $destinationCountry[0],
                    'statuses' => $statuses,
                    'weight' => $weight[1]
                ]);
            }
        }

        return false;
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}VN',
            'C[A-Z]{1}[0-9]{9}VN',
            'E[A-Z]{1}[0-9]{9}VN',
            'H[A-Z]{1}[0-9]{9}VN',
            'L[A-Z]{1}[0-9]{9}VN',
            'N[A-Z]{1}[0-9]{9}VN',
            'P[A-Z]{1}[0-9]{9}VN',
            'Q[A-Z]{1}[0-9]{9}VN',
            'R[A-Z]{1}[0-9]{9}VN',
            'S[A-Z]{1}[0-9]{9}VN',
            'T[A-Z]{1}[0-9]{9}VN',
            'U[A-Z]{1}[0-9]{9}VN',
            'V[A-Z]{1}[0-9]{9}VN'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }
}