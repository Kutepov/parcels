<?php namespace common\components\services;

use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class UkraineEMSService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public $id = 116;
    private $url = 'http://dpsz.ua';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://dpsz.ua/'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            $content = $response->getBody()->getContents();

            preg_match('/value="(.*?)"\sname="track"/is', $content, $track);

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://dpsz.ua/track/ems'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::FORM_PARAMS => [
                    'uds_ems_tr' => $trackNumber,
                    'track' => $track[1],
                    'ems_tr' => ''
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        preg_match('/<table>(.*?)<\/table>/is', $response->getBody()->getContents(), $matches);

        if (!empty($matches[1])) {
            $statuses = [];

            preg_match_all('/<tr>(.*?)<\/tr>/is', $matches[1], $items);

            foreach ($items[1] as $key => $item) {

                if ($key == 0) {
                    continue;
                }

                preg_match_all('/<td.*?>(.*?)<\/td>/is', $item, $data);

                $statuses[] = new Status([
                    'title' => trim($data[1][1]),
                    'date' => $this->createDate($data[1][0]),
                    'location' => trim($data[1][2])
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
            'E[A-Z]{1}[0-9]{9}UA'
        ];
    }
}