<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class ViaCargoService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 233;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.viacargo.com.ar/api/seguimiento/' . $trackNumber . '/'), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);
        $data = $data['ok'][0]['objeto'] ?? false;
        if (!$data) {
            return false;
        }

        $result = new Parcel([
            'departureAddress' => implode(', ' , array_filter([$data['direccionRemitente'] ?: false, $data['poblacionRemitente'] ?: false])),
            'destinationAddress' => implode(', ', array_filter([$data['direccionDestinatario'] ?: false, $data['poblacionDestinatario'] ?: false]))
        ]);

        foreach ($data['listaEventos'] as $checkpoint) {
            $date = Carbon::parse(str_replace('/', '.', $checkpoint['fechaEvento']));

            $result->statuses[] = new Status([
                'title' => $checkpoint['descripcion'],
                'location' => $checkpoint['deleNombre'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
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

    public function restrictCountries()
    {
        return ['es'];
    }
}