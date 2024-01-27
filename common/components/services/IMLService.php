<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class IMLService extends BaseService implements ValidateTrackNumberInterface, ManuallySelectedInterface, CountryRestrictionInterface, AsyncTrackingInterface
{
    public $captcha = true;

    public $id = 108;

    public function track($trackNumber)
    {
        return $this->request($trackNumber)->wait();
    }

    private function request($trackNumber)
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://iml.ru/api/v1/orders/track'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'orderId' => $trackNumber
            ]
        ]);
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->request($trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);

        $result = new Parcel();
        if (isset($json['weight'])) {
            $result->weight = $json['weight'] * 1000;
        }

        foreach ($json['statuses'] as $item) {
            $date = Carbon::parse($item['changedAt']);
            $result->statuses[] = new Status([
                'title' => $item['status'] . ' — ' . $item['globalStatus'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $item['location']
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '[1-9][0-9]{12}'
        ];
    }

    /**
     * @return array
     */
    public function restrictCountries()
    {
        return ['ru', 'kz'];
    }
}