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

class DniproLLCService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, ManuallySelectedInterface
{
    public $id = 241;

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://dniprollc.com/parcel-tracking'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'apply' => 1,
                'cn23' => $trackNumber
            ]
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = new Dom();
        $dom->loadStr($data);

        foreach ($dom->find('table', 0)->find('tr') as $checkpoint) {
            $statuses[] = new Status([
                'title' => trim(strip_tags($checkpoint->find('td', 1)->innerHtml)),
                'date' => $this->createDate(str_replace('-', '.', $checkpoint->find('td', 0)->text)),
                'location' => trim(strip_tags(html_entity_decode($checkpoint->find('td', 2)->innerHtml)))
            ]);
        }

        return isset($statuses) ? new Parcel(['statuses' => $statuses]) : false;
    }

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackNumberRules(): array
    {
        return [];
    }
}