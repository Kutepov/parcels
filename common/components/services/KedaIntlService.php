<?php namespace common\components\services;

use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class KedaIntlService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 140;
    private $url = 'http://www.kdgjwl.com';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'KDW[A-Z]{2}[0-9]{10}YQ'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://www.kdgjwl.com:8082/trackIndex.htm'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'documentCode' => $trackNumber
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        preg_match('/<div class="men_li">(.*?)<\/div>/is', $data, $matches);
        preg_match('/<div class="menu_">(.*?)<\/div>/is', $data, $matchesInfo);
        preg_match_all('/<ul>(.*?)<\/ul>/is', $matchesInfo[1], $info);
        preg_match_all('/<li.*?>(.*?)<\/li>/is', $info[1][1], $destinationCountry);

        if (!empty($matches[1])) {
            $statuses = [];

            preg_match_all('/<ul>(.*?)<\/ul>/is', $matches[1], $items);

            foreach ($items[1] as $k => $item) {
                if (!$k) {
                    continue;
                }

                preg_match_all('/<li.*?>(.*?)<\/li>/is', $item, $data);

                $statuses[] = new Status([
                    'title' => trim(html_entity_decode($data[1][2]), "  "),
                    'date' => $this->createDate(trim(html_entity_decode($data[1][0]), "  ")),
                    'location' => trim(html_entity_decode($data[1][1]), "  ")
                ]);
            }

            return new Parcel([
                'statuses' => $statuses,
                'destinationCountry' => $this->findCountryByCode($destinationCountry[1][2])
            ]);
        }

        return false;
    }
}