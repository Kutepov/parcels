<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\Country;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use yii;

class WinitService extends BaseService implements ServiceInterface, BatchTrackInterface, ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 18; //117
    private $url = 'https://track.winit.com.cn/tracking/Index/getTracking';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', $this->url), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'trackingNoString' => implode(',', (array)$trackNumber),
            ],
            RequestOptions::HEADERS => [
                'User-Agent' => GUZZLE_USERAGENT,
                'Referrer' => 'http://track.winit.com.cn/tracking/Index/result',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'X-Requested-With' => 'XMLHttpRequest'
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $response = json_decode($response->getBody()->getContents(), true);

        $result = new Parcel();

        if (count($response['data']['all'])) {
            foreach ($response['data']['all'] as $data) {

                if ($data['trackingNo'] === $trackNumber) {

                    if ($data['origin']) {
                        $result->departureCountry = $data['origin'];
                    }

                    if ($data['destination']) {
                        $result->destinationCountry = $data['destination'];
                    }

                    if (is_array($data['trace']) && count($data['trace'])) {
                        foreach ($data['trace'] as $checkpoint) {
                            $dateTime = Carbon::parse($checkpoint['date']);

                            $result->statuses[] = new Status([
                                'title' => $checkpoint['eventDescription'],
                                'location' => $checkpoint['location'],
                                'date' => $dateTime->timestamp,
                                'dateVal' => $dateTime->toDateString(),
                                'timeVal' => $dateTime->toTimeString('minute')
                            ]);

                        }
                        return $result;
                    }
                }
            }
        }

        return false;
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 30;
    }

    public function trackNumberRules(): array
    {
        return [
            'ID[0-9]{14}[A-Z]{2}',
            'WO[0-9]{14}[A-Z]{2}',
            '\d{3}BD1B\d{13}',
            '033BD1B\d{5}[A-Z]{1}\d{4}BBE'
        ];
    }
}