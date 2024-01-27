<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use yii\helpers\Json;

class CSEService extends BaseService implements AsyncTrackingInterface, CountryRestrictionInterface, ValidateTrackNumberInterface, BatchTrackInterface
{
    public $id = 210;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(
            new Request('GET', 'https://lk.cse.ru/api/new-track/' . implode(',', (array)$trackNumber)),
            $trackNumber
        );
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = Json::decode($response->getBody()->getContents());

        if (in_array($trackNumber, $json['notfound'], true)) {
            return false;
        }

        $data = null;

        foreach ($json['found'] as $info) {
            if (trim($info['Number']) === $trackNumber) {
                $data = $info;
                break;
            }
        }

        if (is_null($data)) {
            return false;
        }

        if ($data['PlannedDeliveryDate']) {
            try {
                $estimatedDeliveryTime = Carbon::parse($data['PlannedDeliveryDate'])->timestamp;
            } catch (\Throwable $e) {

            }
        }

        $result = new Parcel([
            'departureAddress' => $data['FromGeo'],
            'destinationAddress' => $data['ToGeo'],
            'weight' => $data['Weight'] * 1000,
            'estimatedDeliveryTime' => $estimatedDeliveryTime ?? null
        ]);

        if (!isset($data['History'])) {
            $date = Carbon::parse($data['CreateDate']);

            $result->statuses[] = new Status([
                'title' => $data['State'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }
        else {
            foreach ($data['History'] as $checkpoint) {
                if (!trim($checkpoint['EventName'])) {
                    continue;
                }
                $date = Carbon::parse($checkpoint['EventDate']);

                $location = $checkpoint['EventInfo'];
                if (preg_match('#:(.*?)$#siu', $location, $m)) {
                    $location = $m[1];
                }
                else {
                    $location = null;
                }
                $location = preg_replace('#\([0-9\. -:]+\)#siu', '', $location);
                $location = preg_replace('#\d{2}\.\d{2}\.\d{4}#siu', '', $location);
                $location = trim($location);

                if (stripos($location, 'зарегистрирована накладная') !== false) {
                    $location = null;
                }

                if (preg_match('#^отправитель:(.*?)$#siu', $location, $m)) {
                    $location = null;
                    $result->sender = trim($m[1]);
                }

                $result->statuses[] = new Status([
                    'title' => $checkpoint['EventName'],
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                    'location' => $location
                ]);
            }
        }

        return $result;

    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function restrictCountries()
    {
        return ['ru'];
    }

    public function trackNumberRules(): array
    {
        return ['^\d{3}-\d{7}-\d{5}-\d{2}$'];
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