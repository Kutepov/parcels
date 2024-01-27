<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;

class MoldovaPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 137;
    private $url = 'http://www.posta.md';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}MD',
            'C[A-Z]{1}[0-9]{9}MD',
            'E[A-Z]{1}[0-9]{9}MD',
            'H[A-Z]{1}[0-9]{9}MD',
            'L[A-Z]{1}[0-9]{9}MD',
            'R[A-Z]{1}[0-9]{9}MD',
            'S[A-Z]{1}[0-9]{9}MD',
            'U[A-Z]{1}[0-9]{9}MD',
            'V[A-Z]{1}[0-9]{9}MD'
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
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://trimiteri-api.posta.md/public/track/' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);

        if (!isset($json['shipping_logs'])) {
            return false;
        }

        $result = new Parcel();
        $result->weight = $json['total_weight'];
        $result->sender = $json['sender_name'];
        $result->recipient = $json['receiver_name'];


        foreach ($json['shipping_logs'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['created_at']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['description'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')

            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}