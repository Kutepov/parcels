<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use yii;

class DhlEcommerceService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 251;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://ecommerceportal.dhl.com/track/?ref='), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {

            preg_match('#<form id="trackItNowForm"(.*?)</form>#siu', $response->getBody()->getContents(), $form);
            preg_match('#name="javax\.faces\.ViewState" id="j_id1:javax\.faces\.ViewState:1" value="(.*?)"#siu', $form[1], $viewstate);

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://ecommerceportal.dhl.com/track/'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'faces-request' => 'partial/ajax',
                    'referer' => 'https://ecommerceportal.dhl.com/track/?ref=',
                    'x-dtpc' => '$503516248_393h13vLQLLLPFQGTKOOAUFRNIRNRDBGLACDBRI-0e0',
                    'x-requested-with' => 'XMLHttpRequest'
                ],
                RequestOptions::FORM_PARAMS => [
                    'javax.faces.partial.ajax' => 'true',
                    'javax.faces.source' => 'trackItNowForm:searchSkuBtn',
                    'javax.faces.partial.execute' => '@all',
                    'javax.faces.partial.render' => 'trackItNowForm:searchSkuBtn trackItNowForm messages',
                    'trackItNowForm:searchSkuBtn' => 'trackItNowForm:searchSkuBtn',
                    'trackItNowForm' => 'trackItNowForm',
                    'trackItNowForm:trackItNowSearchBox' => $trackNumber,
                    'hiddenFocus' => '',
                    'trackItNowForm:faqAccordion_active' => 'null',
                    'trackItNowForm:country1_focus' => '',
                    'trackItNowForm:country1_input' => '0',
                    'javax.faces.ViewState' => $viewstate[1]
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        if (stristr($data, 'No result found. This could be due to missing shipment or tracking details.')) {
            return false;
        }

        $data = preg_replace('#<!\[CDATA\[(.*?)\]\]>#siu', '$1', $data);
        preg_match('#<update id="trackItNowForm">(.*?)</update>#siu', $data, $m);
        $dom = new Dom();
        $dom->loadStr($data);

        $currentDate = null;
        $statuses = [];
        foreach ($dom->find('li.timelineDate,li.Timeline-event') as $event) {
            if ($event->getAttribute('class') == 'timelineDate') {
                $currentDate = $event->find('label')->text;
                continue;
            }

            $timeOr = str_replace('  ', ' ', trim(strip_tags($event->find('.timelineTime')->innerHtml)));
            if (!preg_match('#(\d{2}:\d{2} (?:[AP])M)#siu', $timeOr, $time)) {
                continue;
            }

            $dateTimeString = $currentDate . ' ' . $time[1];
            $date = Carbon::parse($dateTimeString);
            if (!$date) {
                continue;
            }

            $statuses[] = new Status([
                'title' => $event->find('.timeline-description', 0)->find('label', 0)->text,
                'location' => $event->find('.timeline-location', 0)->find('label', 0)->text,
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        $result = new Parcel();

        if (isset($statuses)) {

            $info = $dom->find('#trackBox')->find('.row', 1);

            $result = new Parcel([
                'departureAddress' => implode(', ', array_map(function ($data) {
                    return trim($data->text);
                }, $info->find('.col-sm-6', 0)->find('.TrackingFromData')->toArray())),
                'destinationAddress' => implode(', ', array_map(function ($data) {
                    return trim($data->text);
                }, $info->find('.col-sm-6', 1)->find('.TrackingFromData')->toArray())),
                'statuses' => $statuses
            ]);


            $weightFound = false;
            foreach ($dom->find('#faqBox')->find('.row', 0)->find('label') as $info) {
                if ($info->text == 'Weight (g)') {
                    $weightFound = true;
                    continue;
                }

                if ($weightFound) {
                    $result->weight = (int)$info->text * 1000;
                    break;
                }
            }
        }

        return (!empty($result->statuses)) ? $result : false;
    }


    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [];
    }
}