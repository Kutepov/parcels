<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class Bluex extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
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
            '[0-9]{10}' // 6821916953
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.bluex.cl/wp-admin/admin-ajax.php'), $trackNumber,
            [
                RequestOptions::HEADERS => [
                    'content-type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                ],
                RequestOptions::FORM_PARAMS => [
                    'action' => 'getTrackingInfo',
                    'n_seguimiento' => $trackNumber
                ]
            ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dataJson = json_decode($data);
        $dataJson = json_decode($dataJson->data[0]);

        if (count($dataJson->s1->listaDocumentos) === 0) {
            return false;
        }

        $result = new Parcel();

        $result->departureCountryCode = $dataJson->s2->documento->remitente->codigoPais;
        $result->departureCountry = $dataJson->s2->documento->remitente->nombrePais;
        $result->departureAddress = $dataJson->s2->documento->remitente->direccionCompleta;
        $result->sender = $dataJson->s2->documento->remitente->nombre;
        $result->senderPhone =$dataJson->s2->documento->remitente->numeroTelefono;

        $result->destinationCountryCode = $dataJson->s2->documento->destinatario->codigoPais;
        $result->destinationCountry = $dataJson->s2->documento->destinatario->nombrePais;
        $result->destinationAddress = $dataJson->s2->documento->destinatario->direccionCompleta;
        $result->recipient = $dataJson->s2->documento->destinatario->nombre;

        foreach ($dataJson->s2->documento->listaPinchazosNacionales as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint->fecha);
            $result->statuses[] = new Status([
                'title' => $checkpoint->nombreTipo,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}