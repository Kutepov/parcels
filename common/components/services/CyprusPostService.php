<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DomCrawler\Crawler;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;

class CyprusPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface, BatchTrackInterface
{
    public $id = 133;
    private $url = 'https://www.cypruspost.post';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}CY',
            'C[A-Z]{1}[0-9]{9}CY',
            'E[A-Z]{1}[0-9]{9}CY',
            'L[A-Z]{1}[0-9]{9}CY',
            'R[A-Z]{1}[0-9]{9}CY',
            'S[A-Z]{1}[0-9]{9}CY',
            'U[A-Z]{1}[0-9]{9}CY',
            'V[A-Z]{1}[0-9]{9}CY'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.cypruspost.post/'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            $dom = new Crawler($response->getBody()->getContents());
            $input = $dom->filterXPath('//form[@id="track-n-trace-form"]//input[1]')->attr('name');
            $validFrom = $dom->filterXPath('//input[@name="valid_from"]')->attr('value');
            $token = $dom->filterXPath('//input[@name="_token"]')->attr('value');

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.cypruspost.post/track-and-trace/find'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::FORM_PARAMS => [
                    $input => '',
                    'valid_from' => $validFrom,
                    '_token' => $token,
                    'code' => $trackNumber
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        //TODO: Почему-то пустое тело ответа
        dd($response->getBody()->getContents());
        $dom = new Crawler($response->getBody()->getContents());


        $data = $dom->filterXPath('//div[@id="collapse_' . $trackNumber . '"]');

        if (!$data->count()) {
            return false;
        }

        $result = new Parcel();
        $data->filterXPath('//tbody//tr')->each(function (Crawler $checkpoint) use (&$result) {
            $date = Carbon::parse(str_replace('/', '.', $checkpoint->filterXPath('//td[1]')->text()));

            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//td[4]')->text(),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $checkpoint->filterXPath('//td[2]')->text() . ' ' . $checkpoint->filterXPath('//td[3]')->text()
            ]);

        });

        return (!empty($result->statuses)) ? $result : false;
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