<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

class DsvService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 449;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://mydsv.com/app/search/publicShipmentList?q=' . $trackNumber), $trackNumber, [], function (ResponseInterface $response) use ($trackNumber) {
            $json = json_decode($response->getBody()->getContents(), true);
            $id = $json['data'][0]['randomIdentifier'] ?? 'false';

            return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://mydsv.com/app/search/shipment/statuses/' . $id), $trackNumber);
        });
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{20}' // 40257145950098710496
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $events = json_decode($response->getBody()->getContents(), true);

        if (!count($events)) {
            return false;
        }

        $result = new Parcel();

        foreach ($events as $checkpoint) {

            if (count($checkpoint['children'])) {
                foreach ($checkpoint['children'] as $children) {
                    $result->statuses[] = $this->getStatus($children);
                }
            } else {
                $result->statuses[] = $this->getStatus($checkpoint);
            }
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    private function getStatus(array $checkpoint): Status
    {
        $dateTime = Carbon::parse($checkpoint['eventDate']);
        return new Status([
            'title' => $checkpoint['description'],
            'location' => $checkpoint['location']['place'],
            'date' => $dateTime->timestamp,
            'dateVal' => $dateTime->toDateString(),
            'timeVal' => $dateTime->toTimeString('minute'),
        ]);
    }

    public function restrictCountries()
    {
        return [
            'dk',
            'se',
            'mx',
            'cz',
            'no'
        ];
    }
}