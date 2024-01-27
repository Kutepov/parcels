<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;

class _139ExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, ManuallySelectedInterface, BatchTrackInterface
{
    public $id = 219;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://www.139express.com/Home/Sub_Track'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'keyword' => is_array($trackNumber) ? implode("\r\n", $trackNumber) : $trackNumber,
            ]
        ]);
    }
    
    public function parseResponse($response, $trackNumber)
    {
        $data = $this->prepareResponse($response->getBody()->getContents());

        $result = new Parcel();

        foreach ($data[$trackNumber] as $checkpoint) {
            $date = Carbon::parse($checkpoint['date']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['title'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]);
        }
        return (!empty($result->statuses)) ? $result : false;
    }

    private function prepareResponse(string $response): array
    {
        $dom = (new Dom())->loadStr($response);

        $result = [];

        $trackNumber = false;
        foreach ($dom->find('tr') as $tr) {
            if ($tr->find('.tit')->count()) {
                $trackNumber = trim($tr->find('.tit', 0)->find('span', 0)->text);
                continue;
            }
            if ($trackNumber && $tr->find('[align="left"]')->count()) {
                $result[$trackNumber][] = [
                    'date' => $tr->find('[align="left"]', 0)->text,
                    'title' => $tr->find('[align="left"]', 1)->text,
                ];
            }
        }

        return $result;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return ['OTN[A-Z]{2}[0-9]{10}[A-Z]{2}[0-9]'];
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