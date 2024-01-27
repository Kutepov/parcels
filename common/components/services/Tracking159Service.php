<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class Tracking159Service extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $mainData;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->request($trackNumber);
    }

    public function track($trackNumber)
    {
        return $this->request($trackNumber)->wait();
    }

    public function request($trackNumber)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://tracking159.com.br/Consultas/PosicaoCargaTRACKING.aspx?pedido='.$trackNumber), $trackNumber);
    }


    public function parseResponse($response, $trackNumber)
    {

        $data = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($data);

        $date = '';
        $title = '';
        foreach ($dom->find('.tblBorda')->find('.lblTextoNormal') as $item => $checkpoint) {

            if (($item + 1) % 3 == 0) {
                $dateTime = Carbon::parse(str_replace('/', '.',$date));

                $statuses[] = new Status([
                    'title' => $title,
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute')
                ]);
            } elseif (($item + 2) % 3 == 0) {

                $title = $checkpoint->find('.backCinza', 0)->text;
            } else {
                $date = $checkpoint->find('.backCinza', 0)->text;
            }

        }

        return isset($statuses) ? new Parcel(['statuses' => $statuses]) : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z0-9]{14}' // 4AAE67C71D688F
        ];
    }
}