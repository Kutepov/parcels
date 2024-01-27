<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class AftershipService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{22}' // 9374889745009231297557
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://track.aftership.com/api/courier-detect/' . $trackNumber), $trackNumber, [], function (ResponseInterface $response) use ($trackNumber) {
            $courier = json_decode($response->getBody()->getContents(), true)['courier'];
            $body = json_encode([
                'trackingNumbers' => $trackNumber,
                'courier' => $courier[0]['slug'],
                'detectType' => 'AI',
            ]);

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://track.aftership.com/api/direct-tracking?lang=ru'), $trackNumber, [
                RequestOptions::HEADERS => [
                    'content-type' => 'application/json',
                    'Host' => 'track.aftership.com',
                    'Content-Length' => strlen($body),
                ],
                RequestOptions::BODY => $body
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $resultJson = json_decode($data, true);

        if (!count($resultJson['data']['checkpoints'])) {
            return false;
        }

        $result = new Parcel();

        foreach ($resultJson['data']['checkpoints'] as $checkpoint) {
            $dateTime = Carbon::parse($checkpoint['checkpoint_time']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['message'],
                'location' => $checkpoint['location'],
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute')
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}