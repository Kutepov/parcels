<?php

namespace common\components\services;

use common\components\AntiCaptcha\Tasks\Letters;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\Country;
use GuzzleHttp\RequestOptions;
use Yii;

class WELService extends BaseService implements ValidateTrackNumberInterface
{
    public $captcha = true;

    
    public $id = 135;
    private $url = 'http://119.23.238.137';

    public function track($trackNumber)
    {
        $request = $this->get('http://119.23.238.137/default/index/get-track-detail', [], true);

        $captchaImage = $this->get('http://119.23.238.137/default/index/verify-code', [
            RequestOptions::HEADERS => [
                'cookie' => $this->getCookieString($request)
            ]
        ]);

        if (!empty($captchaImage)) {
            $captcha = base64_encode($captchaImage);
        } else {
            return false;
        }

        $captcha = Yii::$app->AntiCaptcha->resolve(new Letters([
            'body' => $captcha,
            'CapMonsterModule' => 'ZennoLab.universal'
        ]));


        if (!empty($captcha)) {

            $request = $this->postWithProxy('http://119.23.238.137/default/index/get-track-detail', [
                'timeout' => 30,
                'connect_timeout' => 30,
                RequestOptions::HEADERS => [
                    'cookie' => $this->getCookieString($request)
                ],
                RequestOptions::FORM_PARAMS => [
                    'code' => $trackNumber,
                    'authCode' => $captcha
                ]
            ]);

            preg_match('/<div class="tabContent".*? style="display:none;">(.*?)<\/div>/is', $request, $matches);
            preg_match('/<tbody id="table-module-list-data">(.*?)<\/div>/is', $request, $matchesInfo);
            preg_match_all('/<td.*?>(.*?)<\/td>/is', $matchesInfo[1], $info);

            if (!empty($matches[1])) {
                $statuses = [];

                preg_match_all('/<tr.*?>(.*?)<\/tr>/is', $matches[1], $statusesHtml);

                foreach ($statusesHtml[1] as $key => $item) {

                    if ($key == 0) {
                        continue;
                    }

                    preg_match_all('/<td.*?>(.*?)<\/td>/is', $item, $data);

                    $statuses[] = new Status([
                        'title' => trim($data[1][2]),
                        'date' => $this->createDate($data[1][0]),
                        'location' => $data[1][1]
                    ]);
                }

                return new Parcel([
                    'statuses' => $statuses,
                    'destinationCountry' => Country::findByCode($info[1][3])
                ]);
            }
        }

        return false;
    }

    public function trackNumberRules(): array
    {
        return [
            'WEL[A-Z]{2}[0-9]{10}YQ'
        ];
    }
}