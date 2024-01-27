<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use yii;

class AnjunStatus extends yii\base\BaseObject
{
    public $location = null;
    public $title = null;
}

class AnjunService extends BaseService implements ServiceInterface, BatchTrackInterface, ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 42;
    private $url = 'http://www.szanjuntrack.com/ajbq.asp?q=%s';

    public function track($trackNumber, $repeat = false)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://www.szanjuntrack.com/szanjuntrack.asp'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar()
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            return $this->sendAsyncRequestWithProxy(new Request('GET', sprintf($this->url, $trackNumber)), $trackNumber, [
                RequestOptions::HEADERS => [
                    'Referer' => 'http://www.szanjuntrack.com/szanjuntrack.asp'
                ],
                RequestOptions::COOKIES => $jar
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $request = rmnl($response->getBody()->getContents());
        $result = new Parcel();

        if (preg_match_all('#<riqi>(.*?)</riqi>[\s+]<sj>(.*?)</sj>[\s+]<zt>(.*?)</zt>[\s+]<add>(.*?)</add>#si', $request, $m, PREG_SET_ORDER)) {
            foreach ($m as $checkpoint) {
                $preparedStatus = self::prepareStatus($checkpoint[3]);
                $dateTime = Carbon::parse($checkpoint[1] . ' ' . $checkpoint[2]);
                $result->statuses[] = new Status([
                    'title' => $preparedStatus->title,
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                    'location' => trim($preparedStatus->location, '  ') ?: trim(html_entity_decode($checkpoint[4]), '  -—')
                ]);
            }
        }
        else {
            return false;
        }

        return $result;
    }

    public static function prepareStatus($title): AnjunStatus
    {
        $title = trim($title);
        $location = null;

        if (preg_match('#(have left the|have been sent to the|have arrived at) (.*?)$#si', $title, $m)) {
            $location = $m[2];
            $title = str_ireplace($m[0], $m[1] . ' city', $title);
        }

        $title = str_ireplace('The goods', 'Parcel', $title);

        return new AnjunStatus([
            'title' => $title,
            'location' => $location
        ]);
    }

    public function batchTrack($trackNumbers = [])
    {
        // TODO: Implement batchTrack() method.
    }

    public function batchTrackMaxCount()
    {
        return 1;
    }

    public function trackNumberRules(): array
    {
        return [
            'ANJ[A-Z]{2}\d{10}YQ'
        ];
    }
}