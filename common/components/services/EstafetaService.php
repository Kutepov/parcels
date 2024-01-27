<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use yii\web\NotFoundHttpException;

class EstafetaService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, BatchTrackInterface, CountryRestrictionInterface
{
    public $id = 216;
    private $estimatedDeliveryTime;
    private $extraInfo = [];

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://cs.estafeta.com'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            preg_match('#name="__RequestVerificationToken" type="hidden" value="(.*?)"#siu', $response->getBody()->getContents(), $token);

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://cs.estafeta.com'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::FORM_PARAMS => [
                    '__RequestVerificationToken' => $token[1],
                    'GuiaCodigo' => $trackNumber,
                    'Cliente' => '',
                    'Referencia' => '',
                    'RangoReferencia' => ''
                ]
            ], function (ResponseInterface $response) use ($trackNumber, $jar) {
                if (preg_match('#data-shipment-index="(.*?)"#siu', $body = $response->getBody()->getContents(), $newTrackNumber)) {
                    if (preg_match("#setScheduledDate\('(.*?)'#siu", $body, $m)) {
                        $date = explode('-', $m[1]);
                        if ($this->estimatedDeliveryTime = Carbon::create($date[2], $date[0], $date[1])) {
                            $this->estimatedDeliveryTime = $this->estimatedDeliveryTime->timestamp;
                        }

                        if (preg_match('#<div class="fontRoman">Servicio:\s?</div>[\s\t]+<div class="fontBold">(.*?)</div>#siu', $body, $m)) {
                            $this->extraInfo['Servicio'] = $m[1];
                        }
                    }
                    return $this->trackDetails($newTrackNumber[1], $jar);
                }

                throw new NotFoundHttpException();
            });
        });
    }

    private function trackDetails($trackNumber, $jar = null)
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://cs.estafeta.com/es/Tracking/GetTrackingItemHistory'), $trackNumber, [
            RequestOptions::COOKIES => $jar ?: new CookieJar(),
            RequestOptions::FORM_PARAMS => [
                'waybill' => $trackNumber
            ],
            RequestOptions::HEADERS => [
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => 'https://cs.estafeta.com/es/Tracking/searchByGet?wayBill=' . $trackNumber . '&wayBillType=0&isShipmentDetail=False'
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = new Dom();

        $dom->loadStr($data);

        $result = new Parcel([
            'estimatedDeliveryTime' => $this->estimatedDeliveryTime,
            'extraInfo' => $this->extraInfo
        ]);

        if (($date = $dom->find('#date')) && $date->count()) {
            $result->estimatedDeliveryTime = Carbon::parse(str_replace('/', '.', $date->text));
        }

        foreach ($dom->find('.historyEventRow') as $checkpoint) {
            $date = str_replace('/', '.', strip_tags($checkpoint->find('.col-xs-2')[0]->innerHtml));
            $time = preg_replace('#[^\d:]#siu', '', $checkpoint->find('.col-xs-9')[0]->find('.col-sm-2')[0]->text);
            $date = Carbon::parse($date . ' ' . $time);

            $result->statuses[] = new Status([
                'title' => trim($checkpoint->find('.col-sm-3')[0]->text),
                'location' => trim($checkpoint->find('.col-sm-7')[0]->text),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        if (!isset($result->statuses)) {
            $parcel = \common\models\Parcel::findByTrackNumber($trackNumber);
            $result->statuses = [
                new Status([
                    'title' => 'La guía ha sido generada sin embargo el envío aún no es depositado en Estafeta',
                    'date' => $parcel->created_at ?? time()
                ])
            ];
        }

        return $result;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [];
    }

    public function batchTrack($trackNumbers = [])
    {
        // TODO: Implement batchTrack() method.
    }

    public function batchTrackMaxCount()
    {
        return 1; //20
    }

    public function restrictCountries()
    {
        return ['mx'];
    }
}