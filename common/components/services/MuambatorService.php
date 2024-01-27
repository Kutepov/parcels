<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use PHPHtmlParser\Dom;

class MuambatorService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' // ON292890354BR
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.muambator.com.br/pacotes/'.$trackNumber.'/detalhes/'), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($data);

        $result = new Parcel();
        $result->destinationCountry = $dom->find('.info-1')->find('p')->text;

        foreach ($dom->find('.tab-content', 0)->find('li') as $checkpoint) {
            $date = Carbon::parse( str_replace('/', '.', trim($checkpoint->find('.out', 0)->text)));

            $result->statuses[] = new Status([
                'title' => $checkpoint->find('strong', 0)->text,
                'location' => trim($checkpoint->text),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]);
        }


        return (!empty($result->statuses)) ? $result : false;
    }
}