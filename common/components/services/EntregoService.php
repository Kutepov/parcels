<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class EntregoService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 213;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://track.entrego.com.ph/track.html?com_code=zph&pm_Id=18,39,84,342'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://track.entrego.com.ph/track-referenceNo/get_status_multiple?com_code=zph&onlyLatest=false&processMasterIds=18&processMasterIds=39&processMasterIds=84&processMasterIds=342&referenceNumber=' . $trackNumber), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'pragma' => 'no-cache',
                    'cache-control' => 'no-cache',
                    'accept' => 'application/json, text/plain, */*',
                    'accept-encoding' => 'gzip, deflate',
                    'referer' => 'https://track.entrego.com.ph/track.html?com_code=zph&pm_Id=18,39,84,342',
                    'x-xsrf-token' => $jar->getCookieByName('XSRF-TOKEN')->getValue()
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        if (!$data || !$data[0]['processTimeLineLogsList']) {
            return false;
        }

        $result = new Parcel();

        foreach ($data[0]['processTimeLineLogsList'] as $checkpoint) {
            $date = Carbon::parse(str_replace('/', '.', $checkpoint['statusTime']));
            $result->statuses[] = new Status([
                'title' => trim($checkpoint['status']),
                'location' => trim($checkpoint['remarks']),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
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
        return ['ph'];
    }
}