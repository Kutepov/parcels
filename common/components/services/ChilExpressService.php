<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use yii\web\BadRequestHttpException;

class ChilExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 236;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://services.wschilexpress.com/agendadigital/api/v3/Tracking/GetTracking?gls_Consulta=' . $trackNumber), $trackNumber, [
            RequestOptions::HEADERS => [
                'Ocp-Apim-Subscription-Key' => '7b878d2423f349e3b8bbb9b3607d4215'
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);
        $result = new Parcel();

        if (count($data['ListTracking'])) {
            foreach ($data['ListTracking'] as $checkpoint) {

                $date = Carbon::parse($checkpoint['fec_track']);
                $result->statuses[] = new Status([
                    'title' => $checkpoint['gls_tracking'],
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute')
                ]);

            }
        }
        else {
            return false;
        }
        return $result;
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
        return ['cl'];
    }
}