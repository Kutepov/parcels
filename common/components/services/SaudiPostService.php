<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class SaudiPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 151;
    private $url = 'https://sp.com.sa';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}SA',
            'C[A-Z]{1}[0-9]{9}SA',
            'E[A-Z]{1}[0-9]{9}SA',
            'H[A-Z]{1}[0-9]{9}SA',
            'L[A-Z]{1}[0-9]{9}SA',
            'R[A-Z]{1}[0-9]{9}SA',
            'S[A-Z]{1}[0-9]{9}SA',
            'U[A-Z]{1}[0-9]{9}SA',
            'V[A-Z]{1}[0-9]{9}SA',
            'SCB[0-9]{13}SA',
            'MALL0000[0-9]{6}',
            'GDS2446[0-9]{8}',
            'GDS2456[0-9]{8}',
            'TRFDR00[0-9]{8}',
            'TRFVR00[0-9]{8}'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://splonline.com.sa/umbraco/api/tools/trackshipment?language=en&shipmentCode=' . $trackNumber), $trackNumber, [
            RequestOptions::TIMEOUT => 30,
            RequestOptions::CONNECT_TIMEOUT => 30,
            RequestOptions::VERIFY => false,
            'retry_on_status' => [502, 503, 506, 403, 400, 429]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $request = json_decode($response->getBody()->getContents(), true);

        if (!empty($request[0]['TrackingInfoItemList'])) {
            $statuses = [];

            foreach ($request[0]['TrackingInfoItemList'] as $key => $item) {

                $date = Carbon::parse($item['EventDate'] . ' ' . $item['EventTime']);
                $statuses[] = new Status([
                    'title' => $item['EventDescription'],
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                    'location' => $item['Office']
                ]);
            }

            return new Parcel([
                'statuses' => $statuses,
                'departureAddress' => $request[0]['Source'],
                'destinationAddress' => $request[0]['Destination']
            ]);
        }

        return false;
    }
}