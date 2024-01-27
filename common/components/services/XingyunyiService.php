<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;

class XingyunyiService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        }
        catch (\Exception $exception) {}
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{5}[0-9]{10}[A-Z]{2}' // XYYEX0015718763YQ
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://wms.xingyunyi.cn/Tracking?numbers='.$trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = (new Dom())->loadStr($data);

        $result = new Parcel();

        foreach ($dom->find('.trackitem-details')->find('dd') as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint->find('time', 0)->text);
            $title = $checkpoint->find('p', 0)->text;
            if ($title === '暂无物流轨迹!') {
                return false;
            }

            $result->statuses[] = new Status([
                'title' => $checkpoint->find('p', 0)->text,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}