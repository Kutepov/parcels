<?php

namespace common\components\services;

use Carbon\Carbon;
use common\components\AntiCaptcha\Client;
use common\components\AntiCaptcha\Tasks\Letters;
use common\components\AntiCaptcha\Tasks\Numbers;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RequestOptions;
use Yii;
use MathParser\StdMathParser;
use MathParser\Interpreting\Evaluator;

class IndiaPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface
{
    public $id = 176;
    public $captcha = true;
    private $url = 'http://www.indiapost.gov.in';

    /** @var Client */
    private $antiCaptchaService;

    public function __construct($data = null)
    {
        $this->antiCaptchaService = Yii::$container->get(Client::class);
        parent::__construct($data);
    }

    public function track($trackNumber)
    {
        $request = $this->getWithProxy('https://www.indiapost.gov.in/_layouts/15/DOP.Portal.Tracking/TrackConsignment.aspx', [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ]);

        preg_match('/__VIEWSTATE.*?value="(.*?)"/is', $request, $__VIEWSTATE);
        preg_match('/__EVENTVALIDATION.*?value="(.*?)"/is', $request, $__EVENTVALIDATION);
        preg_match('/__REQUESTDIGEST.*?value="(.*?)"/is', $request, $__REQUESTDIGEST);

        $tasks = [
            'Evaluate the Expression',
            'Enter characters as displayed in image',
            'Enter the (.*?) number'
        ];

        $captchaResult = null;


        if (preg_match('#<div id="ctl00_PlaceHolderMain_ucNewLegacyControl_upcaptcha">(.*?)</div>#siu', $request, $captchaPage)) {
            if (preg_match('#ucCaptcha1_lblCaptcha" class="required" title="">(.*?)</span>#si', $captchaPage[1], $captchaTask)) {

                if (preg_match('#<img id="ctl00_PlaceHolderMain_ucNewLegacyControl_ucCaptcha1_(?:imgMathCaptcha|imgCaptcha)" class="form-control" src="(.*?)"#siu', $request, $m)) {
                    $captcha = $this->getWithProxy('https://www.indiapost.gov.in/_layouts/15/DOP.Portal.Tracking/' . $m[1], [
                        RequestOptions::COOKIES => $jar
                    ]);
                }

                if (!$captcha) {
                    return false;
                }

                $i = 0;
                foreach ($tasks as $task) {
                    if (preg_match('#' . $task . '#siu', $captchaTask[1], $taskMatch)) {
//                        echo 'task - '.$tasks[$i],' - '.$taskMatch[1].PHP_EOL;
                        switch ($i) {
                            case 0:
                                $captchaResult = $this->antiCaptchaService->resolve(new Letters([
                                    'body' => base64_encode($captcha),
                                    'CapMonsterModule' => 'IndiaPostMath'
                                ]));

                                if (!is_numeric($captchaResult[0])) {
                                    $captchaResult[0] = 7;
                                }
                                if (!in_array($captchaResult[1], ['-', '+'])) {
                                    $captchaResult[1] = '-';
                                }
                                if (!is_numeric($captchaResult[2])) {
                                    $captchaResult[2] = 7;
                                }

                                $parser = new StdMathParser();
                                $AST = $parser->parse($captchaResult);
                                $evaluator = new Evaluator();
                                $captchaResult = $AST->accept($evaluator);
                                break;

                            case 1:
                                $captchaResult = $this->antiCaptchaService->resolve(new Letters([
                                    'body' => base64_encode($captcha),
                                    'CapMonsterModule' => 'ZennoLab.universal'
                                ]));
                                break;

                            case 2:
                                $captchaResult = $this->antiCaptchaService->resolve(new Numbers([
                                    'body' => base64_encode($captcha),
                                    'CapMonsterModule' => 'ZennoLab.universal'
                                ]));

                                $numberPos = [
                                    'First' => 0,
                                    'Second' => 1,
                                    'Third' => 2,
                                    'Fourth' => 3,
                                    'Fifth' => 4
                                ];

                                $captchaResult = $captchaResult[$numberPos[$taskMatch[1]]];
                                break;
                        }
                    }
                    $i++;
                }
            }
        }

//        file_put_contents(Yii::getAlias('@root/india/1.gif'), $captcha);
//        file_put_contents(Yii::getAlias('@root/india/1.txt'), $captcha);
//        echo 'c - ' . $captchaResult . PHP_EOL;

        if (!is_null($captchaResult)) {
            $request = $this->postWithProxy('https://www.indiapost.gov.in/_layouts/15/DOP.Portal.Tracking/TrackConsignment.aspx', [
                'timeout' => 30,
                'connect_timeout' => 30,
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/75.0.3770.142 Safari/537.36',
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Referer' => 'https://www.indiapost.gov.in/_layouts/15/DOP.Portal.Tracking/TrackConsignment.aspx',
                    'x-compress' => 'null',
                    'X-MicrosoftAjax' => 'Delta=true',
                    'X-Requested-With' => 'XMLHttpRequest'
                ],
                RequestOptions::FORM_PARAMS => [
                    'ctl00$ScriptManager' => 'ctl00$PlaceHolderMain$ucNewLegacyControl$upnlTrackConsignment|ctl00$PlaceHolderMain$ucNewLegacyControl$btnSearch',
                    'ctl00$UCLogin1$hdnIsMobileSite' => 'false',
                    'ctl00$PlaceHolderMain$ucNewLegacyControl$hdnMobileSite' => 'false',
                    'ctl00$PlaceHolderMain$ucNewLegacyControl$txtOrignlPgTranNo' => $trackNumber,
                    'ctl00$PlaceHolderMain$ucNewLegacyControl$ucCaptcha1$txtCaptcha' => $captchaResult,
                    '__LASTFOCUS' => '',
                    '__EVENTTARGET' => '',
                    '__EVENTARGUMENT' => '',
                    '__VIEWSTATE' => $__VIEWSTATE[1],
                    '__VIEWSTATEGENERATOR' => 'BA91C67B',
                    '__VIEWSTATEENCRYPTED' => '',
                    '__EVENTVALIDATION' => $__EVENTVALIDATION[1],
                    'MSOWebPartPage_PostbackSource' => '',
                    'MSOTlPn_SelectedWpId' => '',
                    'MSOTlPn_View' => 0,
                    'MSOTlPn_ShowSettings' => 'False',
                    'MSOGallery_SelectedLibrary' => '',
                    'MSOGallery_FilterString' => '',
                    'MSOTlPn_Button' => 'none',
                    'MSOSPWebPartManager_DisplayModeName' => 'Browse',
                    'MSOSPWebPartManager_ExitingDesignMode' => 'false',
                    'MSOWebPartPage_Shared' => '',
                    'MSOLayout_LayoutChanges' => '',
                    'MSOLayout_InDesignMode' => '',
                    'MSOSPWebPartManager_OldDisplayModeName' => 'Browse',
                    'MSOSPWebPartManager_StartWebPartEditingName' => 'false',
                    'MSOSPWebPartManager_EndWebPartEditing' => 'false',
                    '__REQUESTDIGEST' => $__REQUESTDIGEST[1],
                    '__ASYNCPOST' => 'true',
                    'ctl00$PlaceHolderMain$ucNewLegacyControl$btnSearch' => 'Search'
                ],
                RequestOptions::ALLOW_REDIRECTS => false
            ]);

            $result = new Parcel();

            preg_match('/<table.*?responsivetable\s(?:Mail|International)ArticleOER.*?>(.*?)<\/table>/siu', $request, $matches);

            if (!count($matches)) {
                return false;
            }

            preg_match_all('#<th scope="col">(.*?)</th>#siu', $matches[1], $extraTitles, PREG_SET_ORDER);
            preg_match_all('#<td>(.*?)</td>#siu', $matches[1], $extraValues, PREG_SET_ORDER);

            $departureAddress = [];
            $destinationAddress = [];
            foreach ($extraTitles as $k => $title) {

                if (in_array($title[1], ['Source Country', 'Source Office', 'Source Location'])) {
                    $departureAddress[] = $extraValues[$k][1];
                    continue;
                }

                if (in_array($title[1], ['Destination Country', 'Delivery Office', 'Delivery Location'])) {
                    $destinationAddress[] = $extraValues[$k][1];
                    continue;
                }

                if ($title[1] === 'To whom') {
                    $result->recipient = $extraValues[$k][1];
                    continue;
                }
                $result->extraInfo[$title[1]] = $extraValues[$k][1];
            }

            $result->departureAddress = implode(', ', array_unique($departureAddress));
            $result->destinationAddress = implode(', ', array_unique($destinationAddress));

            preg_match('/<table.*?responsivetable\s(?:Mail|International)ArticleEvntOER.*?>(.*?)<\/table>/siu', $request, $matches);

            if (!empty($matches[1])) {
                preg_match_all('/<tr>(.*?)<\/tr>/is', $matches[1], $items);

                foreach ($items[1] as $key => $item) {

                    preg_match_all('/<td>(.*?)<\/td>/', $item, $data);
                    preg_match('/([0-9]{2})\/([0-9]{2})\/([0-9]{4})/sm', $data[1][0], $date);

                    $date = $date[2] . '/' . $date[1] . '/' . $date[3] . ' ' . $data[1][1];
                    $date = Carbon::parse($date);

                    $result->statuses[] = new Status([
                        'title' => trim($data[1][3]),
                        'location' => trim($data[1][2]),
                        'date' => $date->timestamp,
                        'dateVal' => $date->toDateString(),
                        'timeVal' => $date->toTimeString('minute')
                    ]);
                }

                return $result;
            }
        }

        return false;
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}IN',
            'C[A-Z]{1}[0-9]{9}IN',
            'E[A-Z]{1}[0-9]{9}IN',
            'I[A-Z]{1}[0-9]{9}IN',
            'J[A-Z]{1}[0-9]{9}IN',
            'L[A-Z]{1}[0-9]{9}IN',
            'N[A-Z]{1}[0-9]{9}IN',
            'P[A-Z]{1}[0-9]{9}IN',
            'R[A-Z]{1}[0-9]{9}IN',
            'S[A-Z]{1}[0-9]{9}IN',
            'U[A-Z]{1}[0-9]{9}IN',
            'V[A-Z]{1}[0-9]{9}IN',
            'Y[A-Z]{1}[0-9]{9}IN'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }
}