<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class DpdPlService extends BaseService implements ServiceInterface, ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 37;
    private $url = 'https://tracktrace.dpd.com.pl/findPackage';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', $this->url), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'q' => $trackNumber,
                'typ' => 1
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = rmnl($response->getBody()->getContents());
        $result = new Parcel();

        if (preg_match_all('#<tr> <td>(.*?)</td> <td>(.*?)</td> <td>(.*?)</td> <td>(.*?)</td> </tr>#si', $data, $checkpoints, PREG_SET_ORDER)) {
            foreach ($checkpoints as $checkpoint) {
                $dateTime = Carbon::parse($checkpoint[1] . ' ' . $checkpoint[2]);
                $result->statuses[] = new Status([
                    'title' => trim($checkpoint[3]),
                    'location' => trim($checkpoint[4]),
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                ]);
            }
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '\d{14}[A-Z0-9]',
            '\d{14}',
            '\d{10}'
        ];
    }
}