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

class CitylinkexpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 317;

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
            '[0-9]{15}' //060301669394922
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.citylinkexpress.com/wp-json/wp/v2/getTracking'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'tracking' => $trackNumber,
                'xr_option' => 'false',
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $jsonData = json_decode($data, true);
        $jsonData = $jsonData['req']['data'];

        if ($jsonData['returncode'] !== '00') {
            return false;
        }

        $result = new Parcel();
        $result->destinationCountry = $jsonData['destination'];
        $result->departureCountry = $jsonData['origin'];
        $result->weight = $jsonData['weight'] * 1000;

        foreach ($jsonData['trackDetails'] as $checkpoint) {
            $dateTime = Carbon::parse(str_replace('/', '-', $checkpoint['detDate']) . ' ' .$checkpoint['detTime']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['status'],
                'date' => $dateTime->timestamp,
                'location' => $checkpoint['location'],
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }


    public function restrictCountries()
    {
        return ['my'];
    }
}