<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use stdClass;

class JNetService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 23;
    private $url = 'https://www.j-net.cn/service/track?version=new&number=%s';

    public function track($trackNumber)
    {
       return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        $url = sprintf($this->url, $trackNumber);

        return $this->sendAsyncRequestWithProxy(new Request(
            'GET',
            $url,
            [
                'Referrer' => $url,
                'User-Agent' => GUZZLE_USERAGENT
            ]
        ), $trackNumber);
    }
    /**
     * @param Response|stdClass $response
     * @param string $trackNumber
     * @return Parcel|bool
     */
    public function parseResponse($response, $trackNumber)
    {
        $response = rmnl($response->getBody()->getContents());

        if (!stristr($response, '<div class="message"> <div>' . $trackNumber . '&nbsp;</div>')) {
            return false;
        }

        $result = new Parcel();

        if (preg_match('#<div class="base clearfix"> <div style=".*?">(.*?)</div> <div style=".*?">(.*?)</div>#si', $response, $match)) {
            $result->departureAddress = hEncode($match[1]);
            $destination = trim(str_replace('&nbsp;', '', $match[2]));
            $destination = str_ireplace('Russian', 'Russian Federation', $destination);
            $result->destinationAddress = hEncode($match[1]);
        }

        if (preg_match('#<ul class="event">(.*?)</ul>#si', $response, $events)) {
            if (preg_match_all('#<li> ?<span class="time">(.*?)</span>(.*?)</li>#si', $events[1], $checkpoints, PREG_SET_ORDER)) {
                foreach ($checkpoints as $checkpoint) {
                    $statusTitle = array_map('trim', explode(',', trim($checkpoint[2])));
                    $location = null;
                    if (count($statusTitle) == 1) {
                        $status = $statusTitle[0];
                    } else {
                        $location = $statusTitle[0];
                        unset($statusTitle[0]);
                        $status = implode(', ', $statusTitle);
                    }

                    $date = Carbon::parse(trim($checkpoint[1]));

                    $result->statuses[] = new Status([
                        'title' => $status,
                        'location' => $location,
                        'date' => $date->timestamp,
                        'dateVal' => $date->toDateString(),
                        'timeVal' => $date->toTimeString('minute'),
                    ]);
                }
            }
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            'JNTCU[0-9]{10}YQ'
        ];
    }
}