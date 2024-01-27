<?php namespace common\components\services;

use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\Country;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use stdClass;

class ChinaEMSService extends BaseService implements
    ValidateTrackNumberInterface,
    PriorValidateTrackNumberInterface,
    AsyncTrackingInterface,
    BatchTrackInterface
{
    public $id = 43;
    public $captcha = true;
    private $referer = 'http://www.ems.com.cn/mailtracking/e_you_jian_cha_xun.html';
    private $url = 'http://www.ems.com.cn/ems/order/singleQuery_e';
    private $batchUrl = 'http://www.ems.com.cn/ems/order/multiQuery_e';
    private $captchaUrl = 'http://www.ems.com.cn/ems/random?id=singleForm';
    private $batchCaptchaUrl = 'http://www.ems.com.cn/ems/random?id=multiForm';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request(
            'GET',
            is_array($trackNumber) ? $this->batchCaptchaUrl : $this->captchaUrl
        ),
            $trackNumber, [
                RequestOptions::COOKIES => $jar = new CookieJar()
            ], function (ResponseInterface $response) use ($trackNumber, $jar) {
                $v = 'v' . microtime(true) * 1000;

                return $this->sendAsyncRequestWithProxy(new Request(
                    'GET',
                    'http://www.ems.com.cn/ems/GoUploadImg.do?' . $v,
                    [
                        'Referer' => $this->referer
                    ]
                ), $trackNumber, [
                    RequestOptions::COOKIES => $jar
                ], function (ResponseInterface $response) use ($trackNumber, $jar) {
                    $answer = json_decode($response->getBody()->getContents());
                    $uuid = $answer->uuid;

                    $image = base64_decode($answer->YYPng_base64);

                    $img = imagecreatefromstring($image);
                    $wid = imagesx($img);

                    for ($x = 0; $x < $wid; ++$x) {
                        $color = imagecolorat($img, $x, $answer->CJY);
                        $alpha = ($color >> 24) & 255;
                        if ($alpha > 50) {
                            $cjx = $x;
                            break;
                        }
                    }

                    imagedestroy($img);

                    return $this->sendAsyncRequestWithProxy(new Request(
                        'POST',
                        'http://www.ems.com.cn/ems/YanZhenX.do?v' . microtime(true) * 1000
                    ), $trackNumber, [
                        RequestOptions::DELAY => rand(1500, 2500),
                        RequestOptions::COOKIES => $jar,
                        RequestOptions::FORM_PARAMS => [
                            'moveEnd_X' => $cjx + rand(-1, 2),
                            'uuid' => $uuid
                        ],
                    ], function (ResponseInterface $response) use ($trackNumber, $jar) {
                        return $this->sendAsyncRequestWithProxy(new Request(
                            'POST',
                            is_array($trackNumber) ? $this->batchUrl : $this->url,
                            [
                                'Referer' => $this->referer
                            ]
                        ), $trackNumber, [
                            RequestOptions::COOKIES => $jar,
                            RequestOptions::FORM_PARAMS => [
                                is_array($trackNumber) ? 'muMailNum' : 'mailNum' => implode("\r\n", (array)$trackNumber),
                            ]
                        ], null, null, false);
                    }, null, false);
                }, null, false);
            }, null, false);
    }

    /**
     * @param Response|stdClass $response
     * @param string $trackNumber
     * @return Parcel|bool
     */
    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        if (stristr($data, 'Validation Failure, please re-enter verfication code')) {
            return false;
        }

        $data = rmnl($data);
        $data = str_ireplace('&nbsp;', ' ', $data);

        $result = new Parcel([
            'departureCountryCode' => 'CN'
        ]);

        if (preg_match('#<div class="mailnum_result_box">(.*?)</div>#si', $data, $m) && !preg_match('#<ul class="mailnum_result_list_box">(.*?)</ul>#si', $data)) {
            $data = $m[1];

            if (stristr($data, 'No tracking information for the shipment')) {
                return false;
            }

            if (preg_match_all('#<tr align="center"> <td class="td-main\d{1,}" align="center" width="150">(.*?)</td> <td class="td-main\d{1,}" align="center">(.*?)</td> <td align="center" class="td-main\d{1,}">(.*?)</td> </tr>#si', $data, $m, PREG_SET_ORDER)) {
                foreach ($m as $checkpoint) {
                    try {
                        $title = trim($checkpoint[3]);
                        if (!$title) {
                            continue;
                        }

                        $result->statuses[] = new Status([
                            'title' => $title,
                            'date' => $this->createDate(str_replace('  ', ' ', $checkpoint[1]), true),
                            'location' => $checkpoint[2]
                        ]);
                    } catch (\Throwable $e) {
                    }
                }
            }
        }
        /** Мультитрекинг */
        elseif (preg_match('#<ul class="mailnum_result_list_box">(.*?)</ul>#si', $data, $m)) {
            $current = null;
            if (preg_match_all('#<li style="cursor: pointer;">(.*?)</li>#si', $m[1], $m, PREG_SET_ORDER)) {
                foreach ($m as $trackItem) {
                    if (preg_match('#<span style="color:\#005BAC">(?: |)' . preg_quote($trackNumber, '#') . '#si', $trackItem[1])) {
                        $current = $trackItem[1];
                        break;
                    }
                }

                if (is_null($current)) {
                    return false;
                }

                if (preg_match_all('#<tr> <td align="right"(?: class="backcolor"|)>(.*?)</td> <td(?: class="middle_border"| class="backcolor middle_border" align="center")>(.*?)</td> <td(?: | align="center" class="backcolor"|)>(.*?)</td> </tr>#siu', $current, $checkpoints, PREG_SET_ORDER)) {
                    foreach ($checkpoints as $checkpoint) {
                        try {
                            $date = str_replace(' 00:00:00.0', '', $checkpoint[1]);
                            $title = trim($checkpoint[3]);
                            if (!$title) {
                                continue;
                            }
                            $result->statuses[] = new Status([
                                'title' => $title,
                                'date' => $this->createDate(trim(str_replace('   ', ' ', $date)), true),
                                'location' => trim($checkpoint[2])
                            ]);
                        } catch (\Throwable $e) {

                        }
                    }
                }
            }
            else {
                return false;
            }
        }
        else {
            return false;
        }

        return $result;
    }

    public function priorTrackNumberRules(): array
    {
        return [
            'LM\d{9}CN'
        ];
    }

    public function trackNumberRules(): array
    {
        return [
            '(A|B|E|F|L)[A-Z][0-9]{9}CN',
            '(CT|CX|CY)[A-Z][0-9]{9}CN',
            'E[A-Z]\d{9}CS',
            'A\d{12}',
            'KA\d{11}',
            'TC\d{9}HB'
        ];
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 10;
    }
}