<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class PttService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 79;
    protected $url = 'https://gonderitakip.ptt.gov.tr/';


    public function track($trackNumber)
    {
        return $this->request($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}TR',
            'C[A-Z]{1}[0-9]{9}TR',
            'E[A-Z]{1}[0-9]{9}TR',
            'G[A-Z]{1}[0-9]{9}TR',
            'L[A-Z]{1}[0-9]{9}TR',
            'R[A-Z]{1}[0-9]{9}TR',
            'S[A-Z]{1}[0-9]{9}TR',
            'T[A-Z]{1}[0-9]{9}TR',
            'U[A-Z]{1}[0-9]{9}TR',
            'V[A-Z]{1}[0-9]{9}TR',
            'PA[A-Z0-9]{2}[0-9]{7}TR',
            'P[0-9]{11}A',
            'YN\d{9}P1'
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
        return $this->request($trackNumber);
    }

    private function request($trackNumber)
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://gonderitakip.ptt.gov.tr/'), $trackNumber, [], function (ResponseInterface $response) use ($trackNumber) {
            $dom = new Crawler($response->getBody()->getContents());

            $as_sfid = $dom->filterXPath('//input[@name="as_sfid"]')->attr('value');
            $as_fid = $dom->filterXPath('//input[@name="as_fid"]')->attr('value');

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://gonderitakip.ptt.gov.tr/Track/summaryResult'), $trackNumber, [
                RequestOptions::FORM_PARAMS => [
                    'q' => $trackNumber,
                    'as_sfid' => $as_sfid,
                    '$as_fid' =>$as_fid,
                ],
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $html = rmnl($response->getBody()->getContents());
        $dom = new Crawler($html);


        if (!$dom->filterXPath('//div[@id="shipActivity"]')->count()) {
            return false;
        }

        $result = new Parcel();

        if (preg_match('#<li class="list-inline-item"><i></i><b> GÖNDERİCİ : </b> (.*?)</li>#siu', $html, $sender)) {
            $result->sender = $sender[1];
        }

        if (preg_match('#<li class="list-inline-item"><i></i><b> GÖNDERİCİ ADRES : </b> (.*?)</li>#siu', $html, $senderAddress)) {
            $result->departureAddress = html_entity_decode($senderAddress[1]);
        }

        if (preg_match('#<li class="list-inline-item"><i></i><b> ALICI : </b> (.*?)</li>#siu', $html, $recipient)) {
            $result->recipient = $recipient[1];
        }
        if (preg_match('#<li class="list-inline-item"><i></i><b> ALICI ADRES : </b> (.*?)</li>#siu', $html, $recipientAddress)) {
            $result->destinationAddress = html_entity_decode($recipientAddress[1]);
        }

        $result->weightValue = $dom->filterXPath('//h5[@class="purecounter mb-0 fw-bold"][2]')->count() ? $dom->filterXPath('//h5[@class="purecounter mb-0 fw-bold"][2]')->text() : null;

        $dom->filterXPath('//div[@id="shipActivity"]//tbody//tr')->each(function (Crawler $checkpoint) use (&$result) {
            $date = str_replace('/', '.', $checkpoint->filterXPath('//td[1]')->text());
            $date = str_replace(' -', '', $date);
            $date = Carbon::parse($date);

            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//td[2]')->text(),
                'location' => $checkpoint->filterXPath('//td[3]')->text() . ', ' . $checkpoint->filterXPath('//td[4]')->text(),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        });

        return $result;
    }
}