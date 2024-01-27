<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use DateTime;

class ShiplogicService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    private const AWS_ACCESS_KEY = 'AKIA55D5DNTBKXI25J6E';
    private const AWS_SECRET_KEY = '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';
    private const AWS_REGION = 'af-south-1';
    private const AWS_SERVICE = 'execute-api';
    private const AWS_TOKEN = ''; //TODO: Где его взять?

    public $id = 425;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 10;
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}' //UA855633000
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

    /**
     * @param $trackNumber
     * @return PromiseInterface
     */
    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://api.shiplogic.com/tracking/shipments?tracking_ref=' . $trackNumber . '&limit=999'), $trackNumber, [
            RequestOptions::HEADERS => [
                'accept' => '*/*',
                'accept-encoding' => 'gzip, deflate, br',
                'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'authorization' => $this->aws4(),
                'origin' => 'https://ie.shiplogic.com',
                'referer' => 'https://ie.shiplogic.com/',
                'sec-ch-ua' => '"Google Chrome";v="93", " Not;A Brand";v="99", "Chromium";v="93"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
                'sec-fetch-dest' => 'empty',
                'sec-fetch-mode' => 'cors',
                'sec-fetch-site' => 'same-site',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.82 Safari/537.36',
                'x-amz-date' => (new DateTime('UTC'))->format('Ymd\THis\Z'),
            ]
        ]);
    }

    private function aws4()
    {
        $method = 'GET';
        //TODO: Возможно я не правильно понял этот параметр
        $uri = '/tracking/shipments';

        //TODO: Этот код был в оригинале: https://stackoverflow.com/a/42816847 но я не понял его назначения
        /*$json = file_get_contents('php://input');
        $obj = json_decode($json);

        if (isset($obj->method)) {
            $m = explode("|", $obj->method);
            $method = $m[0];
            $uri .= $m[1];
        }

        if ($obj->data == null || empty($obj->data)) {
            $obj->data = "";
        } else
            $param = json_encode($obj->data);
        if ($param == "{}") {
            $param = "";
        }*/


        //TODO: Возможно я не правильно понял этот параметр
        $host = "api.shiplogic.com";
        $alg = 'sha256';

        $date = new DateTime('UTC');

        $dd = $date->format('Ymd\THis\Z');

        $amzdate2 = new DateTime('UTC');
        $amzdate2 = $amzdate2->format('Ymd');
        $amzdate = $dd;

        $algorithm = 'AWS4-HMAC-SHA256';
        $param = "";

        $requestPayload = strtolower($param);
        $hashedPayload = hash($alg, $requestPayload);

        $canonical_uri = $uri;
        $canonical_querystring = '';

        $canonical_headers = "content-type:" . "application/json" . "\n" . "host:" . $host . "\n" . "x-amz-date:" . $amzdate . "\n"; /*. TODO: Не понятно с этим параметром "x-amz-security-token:" . self::AWS_TOKEN . "\n"*/
        $signed_headers = 'content-type;host;x-amz-date;x-amz-security-token';
        $canonical_request = "" . $method . "\n" . $canonical_uri . "\n" . $canonical_querystring . "\n" . $canonical_headers . "\n" . $signed_headers . "\n" . $hashedPayload;


        $credential_scope = $amzdate2 . '/' . self::AWS_REGION . '/' . self::AWS_SERVICE . '/' . 'aws4_request';
        $string_to_sign = "" . $algorithm . "\n" . $amzdate . "\n" . $credential_scope . "\n" . hash('sha256', $canonical_request) . "";

        $kSecret = 'AWS4' . self::AWS_SECRET_KEY;
        $kDate = hash_hmac($alg, $amzdate2, $kSecret, true);
        $kRegion = hash_hmac($alg, self::AWS_REGION, $kDate, true);
        $kService = hash_hmac($alg, self::AWS_SERVICE, $kRegion, true);
        $kSigning = hash_hmac($alg, 'aws4_request', $kService, true);
        $signature = hash_hmac($alg, $string_to_sign, $kSigning);
        return $algorithm . ' ' . 'Credential=' . self::AWS_ACCESS_KEY . '/' . $credential_scope . ', ' . 'SignedHeaders=' . $signed_headers . ', ' . 'Signature=' . $signature;
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);
        dump($data); die;
    }

    public function restrictCountries()
    {
        return ['au'];
    }
}