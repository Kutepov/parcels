<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;

class Gaashwd extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{3}[0-9]{9}' // GWD000279696
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://gaashwd.com/wp-admin/admin-ajax.php'), $trackNumber,
            [
                RequestOptions::FORM_PARAMS => [
                    'action' => 'get_parcel_info',
                    'parcel_id' => $trackNumber,
                ],
            ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $resultJson = json_decode($data);

        if ($resultJson->success === true) {

            $result = new Parcel();

            $html = (new Dom())->loadStr($resultJson->data);

            foreach ($html->find('table', 0)->find('tr') as $item => $checkpoint) {
                if ($item > 0) {
                    $dateTime = Carbon::parse($checkpoint->find('td', 0)->text);
                    $result->statuses[] = new Status([
                        'title' => $checkpoint->find('td', 1)->text,
                        'date' => $dateTime->timestamp,
                        'dateVal' => $dateTime->toDateString(),
                        'timeVal' => $dateTime->toTimeString('minute')
                    ]);
                }
            }

        }

        return (!empty($result->statuses)) ? $result : false;
    }
}