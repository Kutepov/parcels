<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class FreightmarkService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 313;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'MY[A-Z][0-9]{10}' //MYX1061669787
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://www.freightmark.com.my/fmx/result/resultdetail.php?conno=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = (new Dom)->loadStr($response->getBody()->getContents());

        $trackingNumber = $data->find('.mymargin2', 0)->text;

        if (!$trackingNumber) {
            return false;
        }

        $result = new Parcel();
        foreach ($data->find('.frst-timeline-content') as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint->find('.frst-date')->text);
            $result->statuses[] = new Status([
                'title' => $checkpoint->find('strong', 0)->text,
                'location' => $checkpoint->find('p', 1)->text,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }
        return (!empty($result->statuses)) ? $result : false;
    }

}