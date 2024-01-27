<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;

class SailpostService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' // LB044124406LT
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.sailpost.it/ajax/traccia.php'), $trackNumber, [RequestOptions::FORM_PARAMS => [
        'codice' => $trackNumber,
        'versione' => 'v1'
    ]]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($data);

        $result = new Parcel();
        $result->destinationCountryCode = $dom->find('h3', 1)->find('.oro', 0)->text;

        foreach ($dom->find('#eventi', 0)->find('.item') as $checkpoint) {
            $date = Carbon::parse(trim($checkpoint->find('.col-sm-4', 0)->text));

            $result->statuses[] = new Status([
                'title' => $checkpoint->find('.col-sm-4', 0)->find('.d-md-none', 0)->text,
                'location' => $checkpoint->find('.col-sm-5', 0)->text,
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
            ]);
        }


        return (!empty($result->statuses)) ? $result : false;
    }
}