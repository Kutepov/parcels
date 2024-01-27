<?php namespace common\components\services;

use common\components\services\models\Parcel;
use common\models\ParcelStatus;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use common\components\services\models\Status as ResultStatus;

class UkraineExpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface,
    CountryRestrictionInterface
{
    public $id = 295;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://ukraine-express.com'), $trackNumber, [

        ], function (ResponseInterface $response) use ($trackNumber) {
            $currentProxy = $response->getHeader('Proxy-Addr')[0];
            $body = $response->getBody()->getContents();
            $body = mb_convert_encoding($body, 'utf-8', 'windows-1251');
            $dom = (new Dom())->loadStr($body);
            $search_pakage_field1 = $dom->find('#search_pakage_field1', 0)->getAttribute('name');
            $inh = $dom->find('#inh', 0)->text;
            return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://ukraine-express.com/ua/parcelstatus/?' . $search_pakage_field1 . '=15602406&this-for-ue-parcels-only=&we-will-block-any-third-part-software=' . $inh, [
                'Proxy-Addr' => $currentProxy
            ]), $trackNumber);
        });
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{8}' // 16255526
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $data = mb_convert_encoding($data, 'utf-8', 'windows-1251');

        $dom = (new Dom())->loadStr($data);
        if ($dom->find('#block0body')->count() === 0) {
            return false;
        }

        $result = new Parcel();
        $existParcel = \common\models\Parcel::findByTrackNumber($trackNumber);
        $statusTitle = mb_convert_encoding($dom->find('#block0body')->find('h4')->text, 'windows-1251', 'utf-8');
        if (stripos($statusTitle, 'Вантаж з таким кодом не знайдено') !== false) {
            return false;
        }
        $status = \common\models\Status::findOrCreate($statusTitle);
        if ($existParcel && ($existStatus = ParcelStatus::findOne(['status_id' => $status->id, 'parcel_id' => $existParcel->id]))) {
            $result->statuses = [new ResultStatus([
                'statusId' => $existStatus->status_id,
                'date' => $existStatus->date,
            ])];
        }
        else {
            $result->statuses = [new ResultStatus([
                'title' => $statusTitle,
                'date' => time(),
            ])];
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return ['ua'];
    }
}