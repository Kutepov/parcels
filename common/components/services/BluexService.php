<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class BluexService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 288;
    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.blue.cl/wp-admin/admin-ajax.php'), $trackNumber,
            [
                RequestOptions::HEADERS => [
                    'authority' => 'www.blue.cl',
                    'method' => 'POST',
                    'path' => '/wp-admin/admin-ajax.php',
                    'scheme' => 'https',
                    'accept' => 'application/json, text/javascript, */*; q=0.01',
                    'accept-encoding' => 'gzip, deflate, br',
                    'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'content-type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'origin' => 'https://www.blue.cl',
                    'referer' => 'https://www.blue.cl/seguimiento/?n_seguimiento=' . $trackNumber,
                    'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="96", "Google Chrome";v="96"',
                    'sec-ch-ua-mobile' => '?0',
                    'sec-ch-ua-platform' => '"Windows"',
                    'sec-fetch-dest' => 'empty',
                    'sec-fetch-mode' => 'cors',
                    'sec-fetch-site' => 'same-origin',
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.110 Safari/537.36',
                    'x-requested-with' => 'XMLHttpRequest',
                ],
                RequestOptions::FORM_PARAMS => [
                    'action' => 'getTrackingInfo',
                    'n_seguimiento' => $trackNumber
                ]
            ]);
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{10}' // 6821916953
        ];
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
        $result->senderPhone = $dataJson->s2->documento->remitente->numeroTelefono;

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