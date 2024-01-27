<?php namespace common\components\services;

use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use yii;

class ZesExpressService extends BaseService implements ServiceInterface, BatchTrackInterface, ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 17;
    private $url = 'http://120.26.82.200:8080/track_query.aspx?track_number=%s';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    private function processStatus($status): ZesExpressStatus
    {
        $result = new ZesExpressStatus();

        if (preg_match('#^Проехал через г\.(.*),#siu', $status, $match)) {
            $result->status = 'Покинула промежуточный пункт';
            $result->location = 'г. ' . $match[1];
        }
        elseif (preg_match('#(в г\. ?.*+$)#siu', $status, $match)) {
            $result->status = trim(preg_replace('#' . preg_quote($match[1]) . '#si', '', $status));
            $result->location = preg_replace('#^в г\.#si', 'г.', $match[1]);
        }
        else {
            $result->status = $status;
        }

        return $result;
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', sprintf($this->url, $trackNumber)), $trackNumber, [
            'headers' => [
                'User-Agent' => GUZZLE_USERAGENT
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $response = rmnl($response->getBody()->getContents());
        if (stristr($response, 'data-i18n="list.norecord"')) {
            return false;
        }

        $result = new Parcel();

        if (preg_match('#<span data-i18n="order.purposeofthenational">目的国家：</span><span class="msgcss">\((.*?)\)<span>(.*?)</span></span></span>#siu', $response, $countryMatch)) {
            if (!($result->destinationCountryCode = trim($countryMatch[1]))) {
                $result->destinationAddress = trim($countryMatch[2]);
            }
        }

        if (preg_match_all('#<span class=" vertical-date"> <ul> <li> <i class="fa fa-clock-o icss"></i>(.*?) <i class="fa fa-map-marker icss"></i>(.*?) <i class="fa fa-flag icss"></i>(.*?) </li> </ul> </span>#si', $response, $checkpoints, PREG_SET_ORDER)) {
            foreach ($checkpoints as $checkpoint) {
                $cleanStatus = $this->processStatus($checkpoint[3]);
                $result->statuses[] = new Status([
                    'title' => $cleanStatus->status,
                    'location' => $cleanStatus->location ?: ($cleanStatus->location ?: trim($checkpoint[2])),
                    'date' => $this->createDate($checkpoint[1])
                ]);

            }
        }

        return $result;
    }

    public function batchTrack($trackNumbers = [])
    {
        // TODO: Implement batchTrack() method.
    }

    public function batchTrackMaxCount()
    {
        return 1;
    }

    public function trackNumberRules(): array
    {
        return [
            'ZES[A-Z]{2}[0-9]{10}YQ'
        ];
    }
}

class ZesExpressStatus extends yii\base\BaseObject
{
    public $location;
    public $status;
}