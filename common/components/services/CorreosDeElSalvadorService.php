<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class CorreosDeElSalvadorService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 171;

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        } catch (\Exception $exception) {}
    }


    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]SV'
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
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.correos.gob.sv:8000/api/v1/rastrear-paquete/' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);
        if (isset($data['status'])) {
            return false;
        }

        $result = new Parcel([
            'destinationAddress' => $data['envio']['paisDestino'],
            'departureAddress' => $data['envio']['paisOrigen']
        ]);

        foreach ($data['eventos'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['fecha']);

            $result->statuses[] = new Status([
                'title' => implode('. ', array_filter([
                    $checkpoint['descripcion'],
                    trim($checkpoint['razon'], '- '),
                    trim($checkpoint['accion'], '- ')
                ])),
                'location' => trim($checkpoint['oficina'], ' -'),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return $result;
    }
}