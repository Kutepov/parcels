<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class CJCenturyService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        }
        catch (\Exception $exception) {
        }
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{10}' //PP0000220586
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://mysgnexs.cjkx.net/web/g_tracking_eng.jsp?slipno=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($data);

        if (stripos($data, 'No tracking data found') !== false) {
            return false;
        }

        $result = new Parcel();
        foreach ($dom->find('.mb15')->find('tr') as $index => $checkpoint) {

            if ($index === 0) {
                continue;
            }

            $dateTime = Carbon::parse(
                str_replace('/', '-', $checkpoint->find('td', 0)->text)
            );
            $result->statuses[] = new Status([
                'title' => $checkpoint->find('td', 2)->text,
                'date' => $dateTime->timestamp,
                'location' => $checkpoint->find('td', 3)->text,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);

        }

        return (!empty($result->statuses)) ? $result : false;
    }

}