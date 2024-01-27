<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class UBIService extends BaseService implements ValidateTrackNumberInterface, ManuallySelectedInterface, AsyncTrackingInterface, BatchTrackInterface
{
    public $id = 163;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://smartparcel.gotoubi.com/track'), $trackNumber, [
            RequestOptions::FORM_PARAMS => ['trackingNo' => implode(',', (array)$trackNumber)],
            RequestOptions::HEADERS => [
                'Origin' => 'http://smartparcel.gotoubi.com',
                'Pragma' => 'no-cache',
                'Referer' => 'http://smartparcel.gotoubi.com/home',
                'requestType' => 'ajax',
                'X-Requested-With' => 'XMLHttpRequest'
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $request = json_decode($response->getBody()->getContents(), true);

        if (!isset($request['data'][$trackNumber])) {
            return false;
        }

        $data = $request['data'][$trackNumber];

        $result = new Parcel();

        foreach ($data as $checkpoint) {
            $date = Carbon::createFromTimestampMs($checkpoint['dateCreated']);

            $result->statuses[] = new Status([
                'title' => $checkpoint['activity'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $checkpoint['location']
            ]);
        }

        return $result;
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{16}'
        ];
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 30;
    }
}