<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use PHPHtmlParser\Dom;

class SmsaexpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{12}' // 290739490459
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://smsaexpress.com/trackingdetails?tracknumbers='.$trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($data);

        $result = new Parcel();
        $trackingBox = $dom->find('.tracking-box-info', 0)->find('.row', 2);
        $destination = $trackingBox->find('.col-xl-6', 1)->find('p', 1)->text;



        $departure = $trackingBox->find('.col-xl-4', 0)->find('p', 1)->text;
        [$departureCountry, $departureCountryCode] = explode(', ', $departure);
        [$destinationCountry, $destinationCountryCode] = explode(', ', $destination);
        $destinationAddress = $trackingBox->find('.col-xl-6', 1)->find('p', 0)->text;
        $departureAddress = $trackingBox->find('.col-xl-4', 0)->find('p', 0)->text;

        $weightValue = $dom->find('.tracking-box-info', 1)->find('.col-xl-4', 0)->find('p', 0)->text;
        $weight = (double)explode(' ', $weightValue)[0]*1000;

        $result->destinationCountry = trim($destinationCountry);
        $result->destinationCountryCode = trim($destinationCountryCode);
        $result->departureCountry = trim($departureCountry);
        $result->departureCountryCode = trim($departureCountryCode);
        $result->departureAddress = $departureAddress;
        $result->destinationAddress = $destinationAddress;
        $result->weightValue = $weightValue;
        $result->weight = $weight;

        foreach ($dom->find('.tracking-timeline', 0)->find('.row') as $checkpoint) {

            $date = $checkpoint->find('.date-wrap')->find('h4')->text();

            foreach ($checkpoint->find('.trk-wrap') as $item) {

                $dateTime = Carbon::parse($date.' '.$item->find('.trk-wrap-content-left', 0)->find('span', 0)->text);

                $result->statuses[] = new Status([
                    'title' => $item->find('h4', 0)->text,
                    'location' => $item->find('.trk-wrap-content-right', 0)->find('span', 0)->text,
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                ]);
            }
        }


        return (!empty($result->statuses)) ? $result : false;
    }
}