<?php namespace common\components\services;

use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\Country;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Symfony\Component\DomCrawler\Crawler;

class JustinUAService extends BaseService implements AsyncTrackingInterface, ValidateTrackNumberInterface, CountryRestrictionInterface
{
    public $id = 204;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://justin.ua/tracking'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            preg_match('#name="csrf-token" content="(.*?)"#siu', $response->getBody()->getContents(), $token);
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://justin.ua/tracking'), $trackNumber, [
                RequestOptions::FORM_PARAMS => [
                    'number' => $trackNumber,
                    '_token' => $token[1]
                ],
                RequestOptions::COOKIES => $jar,
                RequestOptions::HEADERS => [
                    'Referer' => 'https://justin.ua/tracking?number=' . $trackNumber,
                    'X-Requested-With' => 'XMLHttpRequest',
                    'X-CSRF-TOKEN' => $token[1]
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents());
        $data = $data->html ?? null;

        if (!$data) {
            return false;
        }

        if (stristr($data, 'not-found')) {
            return false;
        }

        $dom = new Crawler($data);

        if (!$dom->filterXPath('//div[@class="statuses"]')->count()) {
            return false;
        }

        $selectorDateOfArrival = '//div[@class="date-of-arrival"]//div[@class="date"]';
        $result = new Parcel([
            /*'departureCountry' => $country =Country::findByCode('ua'),
            'destinationCountry' => $country,*/
            'estimatedDeliveryTime' => $dom->filterXPath($selectorDateOfArrival)->count() ? strtotime(trim($dom->filterXPath($selectorDateOfArrival)->text())) : null
        ]);


        $dom->filterXPath('//div[contains(@class, "wrapper_info")]')->each(function (Crawler $checkpoint) use (&$result) {
            $result->statuses[] =  new Status([
                'title' => $checkpoint->filterXPath('//p')->text(),
                'date' => getTimestamp(),
            ]);
        });

        /*$existParcel = \common\models\Parcel::findByTrackNumber($trackNumber);
        $status = \common\models\Status::findOrCreate($statusTitle);

        if ($existStatus = \common\models\ParcelStatus::findOne(['status_id' => $status->id, 'parcel_id' => $existParcel->id])) {
            $result->statuses = [
                new Status([
                    'statusId' => $existStatus->status_id,
                    'index' => $existStatus->index,
                    'date' => $existStatus->date,
                ])
            ];
        }
        else {
            $result->statuses[] = new Status([
                'title' => $statusTitle,
                'date' => getTimestamp()
            ]);
        }*/

        return $result;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '409\d{6}'
        ];
    }

    public function restrictCountries()
    {
        return ['ua'];
    }
}