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

class EkartLogisticsService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 237;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.ekartlogistics.com/track/' . $trackNumber . '/'), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        if (stristr($data, 'Invalid Tracking ID')) {
            return false;
        }

        $dom = new Dom();
        $dom->loadStr($data);

        $result = new Parcel();

        foreach ($dom->find('table', 1)->find('tbody', 0)->find('tr') as $k => $checkpoint) {
            $day = $checkpoint->find('td', 0)->text;
            $day = explode(' ', $day);
            $day[] = date('Y');
            $day = implode(' ', $day);

            $date = Carbon::parse($day . ' ' . $checkpoint->find('td', 1)->text);

            $result->statuses[] = new Status([
                'title' => trim($checkpoint->find('td', 3)->text),
                'location' => trim($checkpoint->find('td', 2)->text),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return $result;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackNumberRules(): array
    {
        return [
            'FMPC\d{10}'
        ]; //FMPC1072646261
    }

    public function restrictCountries()
    {
        return ['in'];
    }
}