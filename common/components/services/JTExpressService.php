<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Symfony\Component\DomCrawler\Crawler;

class JTExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface, BatchTrackInterface
{
    public $id = 229;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(
            new Request('GET', 'https://www.jtexpress.my/tracking/' . implode(',', (array)$trackNumber)),
            $trackNumber
        );
    }

    public function parseResponse($response, $trackNumber)
    {
        $body = $response->getBody()->getContents();
        $dom = new Crawler($body);

        $result = new Parcel();

        $dom->filter('#tracking-info')->filter('.accordion-item')->each(function (Crawler $node) use ($trackNumber, &$result) {
            $trackItem = $this->clearTrackNumber($node->filter('.fw-bold')->text());
            $date = '';

            if ($trackNumber == $trackItem) {
                $node->filter('.accordion-body')->filter('div[style=" background:white"]')->filterXPath('//div[contains(@class, "row")]')->each(function (Crawler $node) use (&$result, &$date) {
                    if ($node->attr('class') == ' row') {
                        [$date,] = explode(', ', trim($node->filterXPath('//div[@class="text-sm-end fs-5 pt-3"]')->text()));
                    } else {
                        $time = $node->filterXPath('//div[@class="fw-b mt-3"]')->text();
                        $dateTime = Carbon::parse($date . $time);

                        $result->statuses[] = new Status([
                            'title' => trim($node->filter('b')->first()->text(null, true)),
                            'location' => trim($node->filterXPath('//div[@class="fw-light"]')->text()),
                            'date' => $dateTime->timestamp,
                            'dateVal' => $dateTime->toDateString(),
                            'timeVal' => $dateTime->toTimeString('minute')
                        ]);

                    }
                });
            }
        });

        if (!count($result->statuses)) {
            return false;
        }

        if (preg_match('#<div class="col-12 text-start">(.*?)<i class="fas fa-arrow-right mx-2"></i>(.*?)</div>#si', $body, $m)) {
            $result->departureAddress = trim($m[1]);
            $result->destinationAddress = trim($m[2]);
        }

        return $result;
    }

    private function clearTrackNumber(string $trackNumber): string
    {
        return str_replace('&zwj;', '', htmlentities(trim($trackNumber)));
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
        return ['my'];
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function batchTrackMaxCount()
    {
        return 10;
    }
}