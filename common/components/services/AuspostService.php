<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class AuspostService extends BaseService implements ServiceInterface, BatchTrackInterface, ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface, SlowServiceInterface,
    CountryRestrictionInterface
{
    public $id = 34;

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
        return 10;
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}AU',
            '(?!^\d+$)^.{3}[0-9]{7}',
            '333UF4\d{6}',
            '334A71\d{6}',
            '338QV0\d{6}',
            '339GG0\d{6}',
            '33A3A1\d{6}',
            '33A3R1\d{6}',
            '33DA40\d{6}',
            '518240\d{6}',
            '574877\d{6}',
            '574879\d{6}',
            '575735\d{6}',
            'OWXZ\d{8}',
            'OXXZ\d{8}',
            '997\d{15}',
            '00093\d{15}',
            '00393\d{15}',
            '01993126509999989133[A-Z0-9]{21}',
            '(?!^\d+$)^.{3}\d{18}',
            '(?!^\d+$)^.{4}\d{18}',
            '\d{58}',
            '\d{37}',
            'R\d{15}'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

    /**
     * @param $trackNumber
     * @return PromiseInterface
     */
    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://digitalapi.auspost.com.au/shipmentsgatewayapi/watchlist/shipments?trackingIds=' . implode(',', (array)$trackNumber)), $trackNumber, [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json;charset=UTF-8',
                'User-Agent' => 'AusPost/2 CFNetwork/1188 Darwin/20.0.0',
                'api-key' => 'd11f9456-11c3-456d-9f6d-f7449cb9af8e',
                'AP_CHANNEL_NAME' => 'IOS',
                'Accept-Language' => 'en',
                'Accept-Encoding' => 'gzip, deflate',
                'AP_APP_ID' => 'MYPOST'
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);
        if (isset($data[0]['error'])) {
            return false;
        }

        $result = new Parcel();

        foreach ($data as $track) {
            if ($track['shipment']['articles'][0]['articleId'] === $trackNumber || $track['shipment']['consignmentId'] === $trackNumber) {
                foreach ($track['shipment']['articles'][0]['details'][0]['events'] as $checkpoint) {
                    $date = Carbon::parse($checkpoint['localeDateTime']);
                    $tzOffset = $date->getOffset() / 3600;
                    if ($tzOffset > 0) {
                        $tzOffset = '+' . $tzOffset;
                    }
                    $result->statuses[] = new Status([
                        'title' => $checkpoint['description'],
                        'location' => $checkpoint['location'],
                        'date' => $date->timestamp,
                        'dateVal' => $date->toDateString(),
                        'timeVal' => $date->toTimeString('minute'),
                        'timezoneVal' => $tzOffset
                    ]);
                }
            }
        }

        return $result;
    }

    public function restrictCountries()
    {
        return ['au'];
    }
}