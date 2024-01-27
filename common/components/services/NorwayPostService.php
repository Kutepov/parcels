<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use stdClass;

class NorwayPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{

    public $id = 96;
    private $url = 'https://sporing.posten.no';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}NO',
            'C[A-Z]{1}[0-9]{9}NO',
            'E[A-Z]{1}[0-9]{9}NO',
            'I[A-Z]{1}[0-9]{9}NO',
            'K[A-Z]{1}[0-9]{9}NO',
            'L[A-Z]{1}[0-9]{9}NO',
            'P[A-Z]{1}[0-9]{9}NO',
            'R[A-Z]{1}[0-9]{9}NO',
            'S[A-Z]{1}[0-9]{9}NO',
            'U[A-Z]{1}[0-9]{9}NO',
            'V[A-Z]{1}[0-9]{9}NO',
            '70[0-9]{15}',
            '73[0-9]{15}',
            '37[0-9]{16}'
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
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://sporing.posten.no/tracking/api/fetch'), $trackNumber, [
            RequestOptions::QUERY => [
                'lang' => 'no',
                'query' => $trackNumber,
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $request = json_decode($response->getBody()->getContents(), true);
        $eventSet = $request['consignmentSet'][0]['packageSet'][0]['eventSet'];

        if (!empty($eventSet)) {
            $statuses = [];

            foreach ($eventSet as $item) {
                $location = '';

                if (!empty($item['country']) && !empty($item['city'])) {
                    $location = $item['country'] . ' - ' . $item['city'];
                }
                elseif (!empty($item['country'])) {
                    $location = $item['country'];
                }
                elseif (!empty($item['city'])) {
                    $location = $item['city'];
                }

                $date = Carbon::parse($item['dateIso']);

                $statuses[] = new Status([
                    'title' => $item['description'],
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                    'location' => $location
                ]);
            }

            return new Parcel([
                'statuses' => $statuses
            ]);
        }

        return false;
    }
}