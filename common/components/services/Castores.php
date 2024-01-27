<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class Castores extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        }
        catch (\Exception $exception) {}
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{11}' // 14070262068
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://clientes.castores.com.mx/WSPortal/app/services/rastrear?factura='.$trackNumber), $trackNumber,
        [
            RequestOptions::HEADERS => [
                'content-type' => 'text/plain;charset=ISO-8859-1',
            ],
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dataJson = json_decode(utf8_encode($data));

        $result = new Parcel();

        $result->sender = $dataJson->remitente;
        $result->recipient = $dataJson->destinatario;

        foreach ($dataJson->detallesrastro as $checkpoint) {
            [$title, $dateString] = explode(' - ', $checkpoint->mensaje);
            $dateTime = Carbon::parse(trim($dateString));
            $result->statuses[] = new Status([
                'title' => trim($title),
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}