<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Symfony\Component\DomCrawler\Crawler;
use yii\web\NotFoundHttpException;

class MRWService extends BaseService implements
    ValidateTrackNumberInterface,
    AsyncTrackingInterface,
    CountryRestrictionInterface
{
    public $id = 208;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.mrw.es/seguimiento_envios/validar_seguiment.asp'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'mrw-finder-follow-code' => $trackNumber
            ],
            RequestOptions::COOKIES => $jar = new CookieJar(),
            RequestOptions::HEADERS => [
                'referer' => 'https://www.mrw.es/'
            ]
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            $result = $response->getBody()->getContents();
            if (stristr($result, 'No se han encontrado coincidencias con la información introducida.')) {
                throw new NotFoundHttpException();
            }

            return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.mrw.es/seguimiento_envios/MRW_historico_nacional.asp?fecha=2021-12-22&tr=0'), $trackNumber, [
               RequestOptions::COOKIES => $jar,
            ]);
        });
    }

    /**
     * @param Response|stdClass $response
     * @param string $trackNumber
     * @return Parcel|bool
     */
    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = new Crawler($data);
        if (!$dom->filterXPath('//div[@class="table-responsive"]')->count()) {
            return false;
        }

        $result = new Parcel();
        $dom->filterXPath('//div[@class="table-responsive"]//tbody//tr')->each(function (Crawler $checkpoint) use (&$result) {
            $date = str_replace('/', '-', $checkpoint->filterXPath('//td[1]')->text());
            $time = $checkpoint->filterXPath('//td[2]')->text();
            if(!\DateTime::createFromFormat('h:m', $time)) {
                $time = '';
            }

            $dateTime = Carbon::parse($date . ' ' . $time);
            $result->statuses[] = new Status([
                'title' => trim($checkpoint->filterXPath('//td[3]')->text()),
                'date' => $dateTime->timestamp,
                'location' => trim($checkpoint->filterXPath('//td[4]')->text()),
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            'FS\d{9}JB'
        ];
    }

    public function restrictCountries()
    {
        return 'es';
    }
}