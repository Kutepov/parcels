<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class BabService extends BaseService implements ValidateTrackNumberInterface, ManuallySelectedInterface, CountryRestrictionInterface, AsyncTrackingInterface
{
    public $captcha = true;

    public $id = 466;

    public function track($trackNumber)
    {
        return $this->request($trackNumber)->wait();
    }

    private function request($trackNumber)
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://bab.kingtrans.cn/WebTrack?action=list'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
            RequestOptions::FORM_PARAMS => [
                'language' => 'zh',
                'istrack' => 'false',
                'bills' => $trackNumber,
                'Submit' => '查询',
            ],
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://bab.kingtrans.cn/WebTrack?action=repeat'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::FORM_PARAMS => [
                    'index' => '0',
                    'billid' => $trackNumber,
                    'isRepeat' => 'no',
                    'language' => 'zh',
                ],
            ]);
        });
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->request($trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $content = $response->getBody()->getContents();
        $dom = new Crawler($content);

        if(!$dom->filterXPath('//trackitem')->count()) {
            return false;
        }

        $result = new Parcel();

        $dom->filterXPath('//trackitem')->each(function (Crawler $checkpoint) use (&$result) {
            $date = Carbon::parse($checkpoint->attr('sdate'));
            $result->statuses[] = new Status([
                'title' => $checkpoint->attr('intro'),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $checkpoint->attr('place'),
            ]);
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            'UJ\d{9}[A-Z]{2}'
        ];
    }

    /**
     * @return array
     */
    public function restrictCountries()
    {
        return [
            'ch',
            'us',
            'kr',
            'th',
            'ru'
        ];
    }
}