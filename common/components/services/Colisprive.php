<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;

class Colisprive extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
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
            '[0-9]{17}' // 52630032358968110
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.colisprive.com/moncolis/pages/detailColis.aspx?numColis='.$trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($data);

        $result = new Parcel();
        $result->destinationAddress = $dom->find('.divDesti')->find('.tdText')->text;

        foreach ($dom->find('.tableHistoriqueColis')->find('.bandeauText') as $checkpoint) {
            $dateStr = '';
            $title = '';

            $checkpoint->find('td')->each(function ($node, $index) use (&$dateStr, &$title) {
               if ($index === 0) {
                   $dateStr = $node->text;
               }
               else {
                   $title = $node->text;
               }
            });

            $dateTime = Carbon::parse($dateStr);
            $result->statuses[] = new Status([
                'title' => $title,
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}
