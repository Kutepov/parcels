<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;

class Sipost472Service extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, InternationalValidateTrackNumberInterface
{
    public $id = 128;
    private $url = 'http://svc1.sipost.co';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://enviosonline.4-72.com.co/envios472/portal/rastrear.php?guia=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $body = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($body);

        if (!$dom->find('.active-services')->count()) {
            return false;
        }

        $result = new Parcel();

        foreach ($dom->find('.active-services', 0)->find('.listdetalle') as $checkpoint) {
            $date = Carbon::parse( str_replace('/', '-', $checkpoint->find('.services-col2')->text));
            $result->statuses[] = new Status([
                'title' => $checkpoint->find('.services-col4')->find('small')->text,
                'location' => $checkpoint->find('.services-col3')->text,
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]);
        }

        return $result;
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}\d{9}CO'
        ];
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}CO',
            'C[A-Z]{1}[0-9]{9}CO',
            'E[A-Z]{1}[0-9]{9}CO',
            'I[A-Z]{1}[0-9]{9}CO',
            'L[A-Z]{1}[0-9]{9}CO',
            'M[A-Z]{1}[0-9]{9}CO',
            'N[A-Z]{1}[0-9]{9}CO',
            'P[A-Z]{1}[0-9]{9}CO',
            'R[A-Z]{1}[0-9]{9}CO',
            'S[A-Z]{1}[0-9]{9}CO',
            'T[A-Z]{1}[0-9]{9}CO',
            'U[A-Z]{1}[0-9]{9}CO',
            'V[A-Z]{1}[0-9]{9}CO',
            'Y[A-Z]{1}[0-9]{9}CO',
            'IP[0-9]{6}CO'
        ];
    }
}