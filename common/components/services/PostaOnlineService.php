<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class PostaOnlineService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 136;
    private $url = 'https://www.postaonline.cz';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}CZ'
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
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.postaonline.cz/trackandtrace/-/zasilka/cislo'), $trackNumber, [
            RequestOptions::TIMEOUT => 30,
            RequestOptions::CONNECT_TIMEOUT => 30,
            RequestOptions::QUERY => [
                'parcelNumbers' => $trackNumber
            ]
        ]);

    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        preg_match('/<table class="datatable2">(.*?)<\/table>/is', $data, $matches);
        preg_match_all('/silky:.*?<strong>(.*?)<\/strong>/is', $data, $matchesInfo);

        if (!empty($matches[1])) {
            $statuses = [];

            preg_match_all('/<tr.*?>(.*?)<\/tr>/is', $matches[1], $items);

            foreach ($items[1] as $key => $item) {

                if ($key < 1) {
                    continue;
                }

                preg_match_all('/<td.*?>(.*?)<\/td>/is', $item, $data);

                $date = Carbon::parse(strip_tags($data[1][0]));

                $statuses[] = new Status([
                    'title' => trim(strip_tags($data[1][1])),
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                    'location' => trim(strip_tags($data[1][3]))
                ]);
            }

            return new Parcel([
                'statuses' => $statuses,
                'weight' => (float)html_entity_decode($matchesInfo[1][1]) * 1000
            ]);
        }

        return false;
    }
}