<?php

namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class CorreosDeCubaService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 80;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.correos.cu/rastreador-de-envios/'), $trackNumber, [], function (ResponseInterface $response) use ($trackNumber) {
            $dom = new Crawler($response->getBody()->getContents());
            $token = $dom->filterXPath('//input[@id="side"]')->attr('value');
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.correos.cu/wp-json/correos-api/enviosweb/'), $trackNumber, [
                RequestOptions::FORM_PARAMS => [
                    'token' => $token,
                    'codigo' => $trackNumber,
                    'anno' => date('Y'),
                    'user' => '',
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);


        $statuses = [];

        if (!count($data['datos'])) {
            return false;
        }
        foreach ($data['datos'] as $item) {
            $date = Carbon::parse($item['fecha']);

            $statuses[] = new Status([
                'title' => $item['estado'],
                'location' => $item['oficina_origen'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return new Parcel([
            'statuses' => $statuses
        ]);
    }


    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}CU',
            'C[A-Z]{1}[0-9]{9}CU',
            'E[A-Z]{1}[0-9]{9}CU',
            'L[A-Z]{1}[0-9]{9}CU',
            'R[A-Z]{1}[0-9]{9}CU',
            'S[A-Z]{1}[0-9]{9}CU',
            'V[A-Z]{1}[0-9]{9}CU',
            'CU{1}[0-9]{9}RT',
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }
}