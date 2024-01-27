<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class RedPackMexicoService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, BatchTrackInterface
{
    public $id = 218;

    const MONTHS = [
        1 => 'enero',
        'febrero',
        'marzo',
        'abril',
        'mayo',
        'junio',
        'julio',
        'agosto',
        'septiembre',
        'octubre',
        'noviembre',
        'diciembre'
    ];

    public function trackAsync($trackNumber): PromiseInterface
    {
        $trackNumbers = is_array($trackNumber) ? implode(',', $trackNumber) : $trackNumber;
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.redpack.com.mx/es/rastreo/?guias=' . $trackNumbers), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        if (!$dom->filterXPath('//div[@class="popup"]')->count()) {
            return false;
        }

        $result = new Parcel();
        $dom->filterXPath('//div[@class="card"]')->each(function (Crawler $block) use ($trackNumber, &$result, $dom) {

            if (stripos($block->filterXPath('//div[@class="card-header"]')->text(), $trackNumber) !== false) {
                $date = $this->parseDate($block->filterXPath('//div[@class="row pb-3"]//div[@class="col-md-6"][2]//p')->text());
                $result->estimatedDeliveryTime = strtotime($date);
                $result->weight = $block->filterXPath('//div[@class="track-text mb-2"][4]')->text() * 1000;

                $block->filterXPath('//div[contains(@id, "historicos")]//div[@class="row pb-1 "]')->each(function (Crawler $item) use (&$result, $trackNumber) {
                    $date = '';
                    $item->filterXPath('//div[contains(@class, "col-md-12")]')->each(function (Crawler $checkpoint) use (&$result, &$date) {
                        if (!trim($checkpoint->filterXPath('//h5')->text())) {
                            return;
                        }

                        if ($checkpoint->attr('class') === 'col-md-12 pl-5 estatus') {
                            $locationTime = $checkpoint->filterXPath('//span[@class="infoadicional"]')->text();
                            $time = substr($locationTime, 0, 5);
                            $location = substr($locationTime, 6);
                            $dateTime = Carbon::parse($date . ' ' .$time);

                            $result->statuses[] = new Status([
                                'title' => $checkpoint->filterXPath('//h5')->text(),
                                'location' => $location,
                                'date' => $dateTime->timestamp,
                                'dateVal' => $dateTime->toDateString(),
                                'timeVal' => $dateTime->toTimeString('minute')
                            ]);
                        } else {
                            $date = $this->parseDate($checkpoint->filterXPath('//h5')->text());
                        }
                    });
                });
            }
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    private function parseDate(string $date): string
    {
        [$day, $month, $year] = explode(' - ', $date);
        $month = array_flip(self::MONTHS)[lcfirst($month)];
        return $day . '-' . $month . '-' . $year;
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
        return ['mx'];
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 30;
    }
}