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
use yii;

class TresguerrasService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 223;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.tresguerras.com.mx/3G/assets/Ajax/tracking_Ajax.php'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'idTalon' => $trackNumber,
                'action' => 'Talones',
                'esKiosko' => false
            ],
            RequestOptions::HEADERS => [
                'Referer' => 'https://www.tresguerras.com.mx/3G/tracking.php',
                'X-Requested-With' => 'XMLHttpRequest'
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());
        if (!$dom->filterXPath('//table[@class="table table-striped"]')->count()) {
            return false;
        }

        $parcel = new Parcel();

        $parcel->weightValue = $dom->filterXPath('//div[@class="ft-counterup-text headline pera-content"]//h3')->first()->text();
        $parcel->recipient = $dom->filterXPath('//div[@class="ft-about-feature-text headline pera-content"]//p[1]')->text();

        $dom->filterXPath('//table[@class="table table-striped"]//tr')->each(function(Crawler $checkpoint) use ($parcel) {

            if ($checkpoint->filterXPath('//th')->count()) {
                return;
            }

            Carbon::setLocale('es');

            $dateString = $checkpoint->filterXPath('//td[3]')->text();
            $dateArr = explode(' ', $dateString);

            $dateString = [];
            foreach ($dateArr as $key => $item) {
                if ($item === '' || $key === 0 || $item === 'DE' || $item === 'DEL' || $item === 'hrs.') {
                    continue;
                }
                $dateString[] = $item;
            }

            $dateString = implode(' ', $dateString);

            $dateString = strtr(mb_strtolower($dateString), [
                ' enero ' => '.01.',
                ' febrero ' => '.02.',
                ' marzo ' => '.03.',
                ' abril ' => '.04.',
                ' mayo ' => '.05.',
                ' junio ' => '.06.',
                ' julio ' => '.07.',
                ' agosto ' => '.08.',
                ' septiembre ' => '.09.',
                ' octubre ' => '.10.',
                ' noviembre ' => '.11.',
                ' diciembre ' => '.12.'
            ]);

            $dateString = str_replace(' de.', '.', $dateString);
            $dateString = str_replace(' del ', '', $dateString);

            $date = Carbon::parse(trim($dateString));

            $parcel->statuses[] = new Status([
                'title' => trim($checkpoint->filterXPath('//td[1]')->text()),
                'location' => trim($checkpoint->filterXPath('//td[2]')->text()),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);

        });

        return (!empty($parcel->statuses)) ? $parcel : false;
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
        return ['es', 'mx'];
    }
}