<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class UkraineExpress extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        }
        catch (\Exception $exception) {}
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{8}' // 16255526
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://ukraine-express.com'), $trackNumber, [

        ], function (ResponseInterface $response) use ($trackNumber) {
            $body = $response->getBody()->getContents();
            $dom = (new Dom())->loadStr($body);
            $search_pakage_field1 = $dom->find('#search_pakage_field1', 0)->getAttribute('name');
            $inh = $dom->find('#inh', 0)->text;

            return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://ukraine-express.com/ua/status/?'.$search_pakage_field1.'='.$trackNumber.'&this-for-ue-parcels-only=&we-will-block-any-third-part-software='.$inh), $trackNumber);
        });
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
        $result->statuses = new Status([
            'title' => mb_convert_encoding($dom->find('#block0body')->find('h4')->text, 'windows-1251', 'utf-8')
        ]);

        return (!empty($result->statuses)) ? $result : false;
    }
}