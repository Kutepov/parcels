<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class FARInternationalService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 112;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://m.far800.com/index.php'), $trackNumber, [
            RequestOptions::TIMEOUT => 30,
            RequestOptions::CONNECT_TIMEOUT => 30,
            RequestOptions::QUERY => [
                'controller' => 'far800',
                'action' => 't',
                'num' => $trackNumber
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        preg_match('/<tbody>(.*?)<\/tbody>/isu', $response->getBody()->getContents(), $matches);

        if (!empty($matches[1])) {
            $statuses = [];

            preg_match_all('/<tr>(.*?)<\/tr>/isu', $matches[1], $items);

            foreach ($items[1] as $key => $item) {

                preg_match_all('/<td.*?>(.*?)<\/td>/sm', $item, $data);
                preg_match('/【(.*?)】(.*?)$/sm', trim($data[1][1]), $titleAndLocation);

                $title = trim(preg_replace('/(or.*?$)/', '', strip_tags(trim($titleAndLocation[2]))) ?: strip_tags(trim($data[1][1])));

                if (!$title) {
                    continue;
                }

                $date = Carbon::parse(strip_tags($data[1][0]));

                $statuses[] = new Status([
                    'title' => $title,
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                    'location' => $titleAndLocation[1]
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
            'FAR[A-Z]{2}[0-9]{10}YQ'
        ];
    }
}