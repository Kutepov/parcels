<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use stdClass;

class ClevyLinksService extends BaseService implements BatchTrackInterface, ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 40;
    public $api = true;
    private $url = 'http://api.clevylinks.com/api/v2/RouteTrack';

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', $this->url, [
            'Authorization' => 'Basic amF2YVdlYlNpdGU6SjQlaFlTWHk=',
            'Referrer' => 'http://clevylinks.com/en/search.html',
        ]), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'lang' => 'en',
                'MailNos' => (array)$trackNumber,
            ],
            'retry_on_status' => [500, 502, 503, 506, 403, 429]
        ], null, function () {
            return false;
        });
    }

    /**
     * @param Response|stdClass $response
     * @param string $trackNumber
     * @return Parcel|bool
     */
    public function parseResponse($response, $trackNumber)
    {
        $response = json_decode($response->getBody()->getContents());

        if (json_last_error()) {
            return false;
        }

        if ($response->Code != 'SUCCESS') {
            return false;
        }

        $result = new Parcel();

        foreach ($response->ResponseMessage as $data) {
            if ($data->MailNo === $trackNumber) {
                if ($data->Status == 5) {
                    return false;
                }

                foreach ($data->Routes as $checkpoint) {
                    $dateTime = Carbon::parse($checkpoint->EventDate);
                    $result->statuses[] = new Status([
                        'title' => $checkpoint->EventStatus,
                        'date' => $dateTime->timestamp,
                        'location' => $checkpoint->EventSite,
                        'dateVal' => $dateTime->toDateString(),
                        'timeVal' => $dateTime->toTimeString('minute'),
                    ]);
                }

            }
        }

        return $result;
    }

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
        return 2;
    }

    public function trackNumberRules(): array
    {
        return [
            'UH[0-9]{9}GE'
        ];
    }
}