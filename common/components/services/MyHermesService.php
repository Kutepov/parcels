<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use PHPHtmlParser\Dom;
use common\components\services\models\Status;

class MyHermesService extends BaseService implements ServiceInterface, ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 36;
    private $url = 'https://international.evri.com/tracking/';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', $this->url . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = (new Dom())->loadStr($data);
        $result = new Parcel();

        foreach ($dom->find('.trackingInfoSection__trackingTable')->find('.trackingInfoSection__mainBody') as $index => $checkpoint) {

            if ($index > 0) {
                $dateTime = $this->prepareDate($checkpoint);

                $result->statuses[] = new Status([
                    'title' => $checkpoint->find('.trackingInfoSection__trackingTableData', 1)->text(),
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                ]);
            }
        }

        return $result;
    }

    private function prepareDate($checkpoint)
    {
        $date = $checkpoint->find('.trackingInfoSection__trackingTableData', 0)->text();
        $date = str_replace('/', '.', $date);
        return Carbon::parse(str_replace(' -', '', $date));

    }

    public function trackNumberRules(): array
    {
        return [
            '\d{16}' //3340818979070087
           ];
    }
}