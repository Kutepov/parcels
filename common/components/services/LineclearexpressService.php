<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class LineclearexpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, BatchTrackInterface, CountryRestrictionInterface
{
    public $id = 320;

    public function batchTrackMaxCount()
    {
        return 10;
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }


    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        }
        catch (\Exception $exception) {
        }
    }

    public function trackNumberRules(): array
    {
        return [
            'ZN[A-Z]{1}[0-9]{12}' //ZND162161739950
        ];
    }

    public function trackAsync($trackNumbers): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://lineclearexpress.com/tracker'), $trackNumbers, [], function (ResponseInterface $response) use ($trackNumbers) {
            $data = $response->getBody()->getContents();
            $js = [];
            preg_match('/main.(.*?).js/is', $data, $js);
            return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://lineclearexpress.com/' . $js[0]), $trackNumbers, [], function (ResponseInterface $response) use ($trackNumbers) {
                $data = $response->getBody()->getContents();
                $token = [];
                preg_match('/this.token="(.*?).,this.tokenCMS/is', $data, $token);
                $body = json_encode([
                    'SearchType' => 'WayBillNumber',
                    'WayBillNumber' => is_array($trackNumbers) ? $trackNumbers : explode(',', $trackNumbers),
                ]);
                return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://8ym3webome.execute-api.ap-south-1.amazonaws.com/production/1.0/viewandtrack'), $trackNumbers, [
                    RequestOptions::HEADERS => [
                        'authorization' => 'Bearer ' . $token[1],
                        'content-type' => 'application/json',
                    ],
                    RequestOptions::BODY => $body,
                ], null, function () {
                    return false;
                });
            });

        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        $result = new Parcel();
        foreach ($data as $track) {
            foreach ($track as $checkpoint) {
                if ($checkpoint['WayBillNumber'] !== $trackNumber) {
                    continue;
                }
                $dateTime = Carbon::parse(
                    str_replace('/', '-', $checkpoint['LastModifiedOn'])
                );

                $result->statuses[] = new Status([
                    'title' => $checkpoint['Description'],
                    'location' => $checkpoint['TransitLocation'],
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                ]);
            }
        }
        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return ['my', 'sg'];
    }
}