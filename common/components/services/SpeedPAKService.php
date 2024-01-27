<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use stdClass;
use yii;

class SpeedPAKService extends BaseService implements ServiceInterface, BatchTrackInterface, ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 46;
    private $apiUrl = 'http://azure-cn.orangeconnex.com/oc/capricorn-website/website/v1/tracking/traces';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 2;
    }

    public function trackNumberRules(): array
    {
        return [
            'E[A-Z][0-9]{13}[A-Z]{2}[0-9]{8}[A-Z][A-Z\d]{2}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', $this->apiUrl), $trackNumber, [
            RequestOptions::JSON => [
                'trackingNumbers' => (array)$trackNumber,
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $response = json_decode($response->getBody()->getContents());

        if (!$response->success || count($response->result->notExistsTrackingNumbers)) {
            return false;
        }

        $response = $response->result->waybills;

        foreach ($response as $track) {
            if ($track->trackingNumber === $trackNumber) {

                $result = new Parcel([
                    'departureCountryCode' => $track->consignmentCountryCode,
                    'departureAddress' => $track->consignmentCityName,
                    'destinationCountryCode' => $track->consigneeCountryCode,
                    'destinationAddress' => $track->consigneeCityName,
                ]);

                foreach ($track->traces as $checkpoint) {
                    $dateTime = Carbon::parse($checkpoint->oprTime);
                    $result->statuses[] = new Status([
                        'title' => $checkpoint->eventDesc,
                        'location' => implode(', ', array_filter([$checkpoint->oprCity ?? false, $checkpoint->oprCountry ?? false])),
                        'date' => $dateTime->timestamp,
                        'dateVal' => $dateTime->toDateString(),
                        'timeVal' => $dateTime->toTimeString('minute'),
                    ]);

                }
            }
        }

        return $result;

    }
}