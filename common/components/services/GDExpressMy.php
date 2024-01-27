<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class GDExpressMy extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, BatchTrackInterface
{
    public $id = 234;

    public function trackAsync($trackNumber): PromiseInterface
    {
        $preparedTrackNumber = is_array($trackNumber) ? implode(' ', $trackNumber) : $trackNumber;
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://esvr5.gdexpress.com/WebsiteEtracking/Home/Etracking?id=GDEX&input=' . $preparedTrackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = new Crawler($data);

        if (!$dom->filterXPath('//table[@class="table trackTable"]')->count()) {
            return false;
        }

        $result = new Parcel();
        $dom->filterXPath('//table[@class="table trackTable"]')->each(function (Crawler $tableTrack) use (&$result, $trackNumber) {
            $titleTable = $tableTrack->filterXPath('//thead//tr//th[@colspan="4"]')->text();
            $issetCheckpoints = !(bool)$tableTrack->filterXPath('//tbody//tr//td[@colspan="4"]')->count();
            if (
                $titleTable === 'Consignment No: ' . $trackNumber &&
                $issetCheckpoints
            ) {

                $tableTrack->filterXPath('//tbody//tr')->each(function(Crawler $checkpoint) use (&$result) {
                    $date = Carbon::parse(str_replace('/', '-', $checkpoint->filterXPath('//td[2]')->text()));

                    $result->statuses[] = new Status([
                        'title' => $checkpoint->filterXPath('//td[3]')->text(),
                        'location' => $checkpoint->filterXPath('//td[4]')->text(),
                        'date' => $date->timestamp,
                        'dateVal' => $date->toDateString(),
                        'timeVal' => $date->toTimeString('minute')
                    ]);
                });

            }
        });

        return empty($result->statuses) ? false : $result;

    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{11}' //MY10011493439
        ];
    }

    public function restrictCountries()
    {
        return ['my', 'sg'];
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 5;
    }
}