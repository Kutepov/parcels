<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;

class WhistlService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 225;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://trackmyitem.whistl.co.uk/tracking/' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        if (stristr($data, 'Unfortunately no tracking information was found using the reference that you provided.')) {
            return false;
        }

        $dom = new Dom();
        $dom->loadStr($data);

        foreach ($dom->find('.table.table-striped tbody tr') as $checkpoint) {
            $date = strtr($checkpoint->find('td', 0)->text, [
                ' - ' => ' ',
                '/' => '.'
            ]);

            $date = Carbon::parse($date);
            $statuses[] = new Status([
                'title' => trim($checkpoint->find('td', 1)->text),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return isset($statuses) ? new Parcel(['statuses' => $statuses]) : false;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackNumberRules(): array
    {
        return [];
    }
}