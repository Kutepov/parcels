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

class DpeService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, BatchTrackInterface
{
    public $id = 314;

    public function batchTrackMaxCount()
    {
        return 30;
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }


    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        } catch (\Exception $exception) {
        }
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{3}[0-9]{11}' //DPE59002526590
        ];
    }

    public function trackAsync($trackNumbers): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://www.dpe.net.cn/Tracking.php'), $trackNumbers, [
            RequestOptions::FORM_PARAMS => [
                'comefrom' => 'original',
                'tracknumbers' => is_array($trackNumbers) ? implode("\r\n", $trackNumbers) : $trackNumbers
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = (new Dom)->loadStr($response->getBody()->getContents());

        if (!$data->find('.showDetail')->count()) {
            return false;
        }

        $result = new Parcel();
        foreach ($data->find('.showDetail') as $track) {
            foreach ($track->find('#tr_' . $trackNumber) as $checkpoint) {

                $dateTime = Carbon::parse($checkpoint->find('td', 0)->text);

                $result->statuses[] = new Status([
                    'title' => $checkpoint->find('td', 2)->text,
                    'location' => $checkpoint->find('td', 1)->text,
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                ]);
            }
        }
        return (!empty($result->statuses)) ? $result : false;
    }

}