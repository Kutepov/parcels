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

class Com1expressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, BatchTrackInterface
{
    public function batchTrackMaxCount()
    {
        return 10;
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
            '[0-9]{11}' //10212669390
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://com1express.net/api/tracking.html'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'shipments' => is_array($trackNumber) ? implode(',', $trackNumber) : $trackNumber,
                'language' => 'en',
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        $result = new Parcel();
        foreach ($data as $track) {
            if ($track['HAWBNo'] !== $trackNumber) {
                continue;
            }
            foreach ($track['Status'] as $checkpoint) {
                $dateTime = Carbon::parse($checkpoint['TrackingDate']);

                $result->statuses[] = new Status([
                    'title' => $checkpoint['Description'],
                    'location' => $checkpoint['Location'],
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                ]);
            }
        }
        return (!empty($result->statuses)) ? $result : false;
    }

}