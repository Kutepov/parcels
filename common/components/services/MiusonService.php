<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class MiusonService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 156;
    private $url = 'http://211.159.182.134';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://211.159.182.134:8082/en/trackIndex.htm'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'documentCode' => $trackNumber
            ],
            RequestOptions::TIMEOUT => 30,
            RequestOptions::CONNECT_TIMEOUT => 30
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        preg_match('/<div class="men_li">(.*?)<\/div>/is', $response->getBody()->getContents(), $matches);

        if (!empty($matches[1])) {
            $statuses = [];

            preg_match_all('/<tr>(.*?)<\/tr>/is', $matches[1], $items);

            foreach ($items[1] as $key => $item) {
                if ($key == 0) {
                    continue;
                }

                preg_match_all('/<td.*?>(.*?)<\/td>/is', $item, $matches);

                $date = Carbon::parse(trim(html_entity_decode($matches[1][0]), "  "));

                $statuses[] = new Status([
                    'title' => trim(html_entity_decode($matches[1][2]), "  "),
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                    'location' => trim(html_entity_decode($matches[1][1]), "  ")
                ]);
            }

            return new Parcel([
                'statuses' => $statuses
            ]);
        }

        return false;
    }

    public function trackNumberRules(): array
    {
        return [
            'MXA[A-Z]{2}[0-9]{10}YQ'
        ];
    }
}