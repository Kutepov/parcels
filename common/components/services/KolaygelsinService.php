<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class KolaygelsinService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
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
            '[A-Z]{1}[0-9]{6}' // D895233
        ];
    }

    private function getHost()
    {
        return 'https://esube.kolaygelsin.com/';
    }

    private function getAuthToken($url)
    {
        $response = $this->guzzle->get($url);
        $html = $response->getBody()->getContents();
        preg_match("/main.*.js/", $html, $jsUrl);
        $response = $this->guzzle->get($this->getHost().$jsUrl[0]);
        $jsCode = $response->getBody()->getContents();
        preg_match("/genericToken.*,cdnUrl/", $jsCode, $token);
        return substr($token[0], 14, -8);
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        $authToken = $this->getAuthToken('https://esube.kolaygelsin.com/detail/'.$trackNumber);
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://service.kolaygelsin.com/api/request/getcustomershipmentdetails'), $trackNumber, [
            RequestOptions::HEADERS => [
                'Authorization' => 'bearer '.$authToken
            ],
            RequestOptions::FORM_PARAMS => [
                'RequestValue' => $trackNumber,
                'RequestType' => 5
            ]
        ], function (ResponseInterface $response) use ($authToken, $trackNumber) {
            $data = $response->getBody()->getContents();
            $dataJson = json_decode($data);
            $shipmentId = $dataJson->Payload->ShipmentId;

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://service.kolaygelsin.com/api/request/GetCustomerShipmentHistory'), $trackNumber, [
                RequestOptions::HEADERS => [
                    'Authorization' => 'bearer '.$authToken
                ],
                RequestOptions::FORM_PARAMS => [
                    'ShipmentId' => $shipmentId,
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dataJson = json_decode($data);

        $result = new Parcel();

        foreach ($dataJson->Payload as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint->CreateDate);
            $result->statuses[] = new Status([
                'title' => $checkpoint->EventCustomerDescription,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}