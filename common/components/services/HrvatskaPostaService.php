<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class HrvatskaPostaService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 443;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://posiljka.posta.hr/hr/tracking/trackingdata?barcode=' . $trackNumber), $trackNumber);
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}HR' // LE721249336HR
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        if (!$dom->filterXPath('//div[@class="track__n__trace-events-content"]')->count()) {
            return false;
        }

        $result = new Parcel();

        $dom->filterXPath('//div[@class="track__n__trace-events-content"]//div[@class="event__status__item d-flex flex-column justify-content-start align-content-start"]')->each(function (Crawler $checkpoint) use (&$result) {
            $date = trim($checkpoint->filterXPath('//span[@class="mr-5 mb-2 Text-Style-20-md-6"]')->text());
            $date = explode("\r\n", $date);
            $dateTime = Carbon::parse($date[0] . ' '. trim($date[1]));
            $isLocationIsset = (bool)$checkpoint->filterXPath('//div[@class="col-6 col-lg-8 d-flex flex-column justify-content-start"]//span[@class="text-style-52-md-4"]')->count();
            $location = $isLocationIsset ? $checkpoint->filterXPath('//div[@class="col-6 col-lg-8 d-flex flex-column justify-content-start"]//span[@class="text-style-52-md-4"]')->text() : null;

            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//div[@class="col-6 col-lg-8 d-flex flex-column justify-content-start"]//span[contains(@class, "mr-5 mb-2")]')->text(),
                'location' => $location,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);

        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'hr'
        ];
    }
}