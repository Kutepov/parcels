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

class KweService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    private $commonData = [];

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        } catch (\Exception $exception) {
        }
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{6}[A-Z]{6}[0-9]{1}[A-Z]{1}' // 210505RRECHV8N
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://css.kwe.com/ReferenceLoginAction.do'), $trackNumber,
            [
                RequestOptions::COOKIES => $jar = new CookieJar(),
                RequestOptions::FORM_PARAMS => [
                    'waybillNo' => $trackNumber,
                    'pageName' => 'L',
                    'No' => $trackNumber,
                    'tracking' => 'https://css.kwe.com/ReferenceLoginAction.do'
                ],
            ],
            function (ResponseInterface $response) use ($trackNumber, $jar) {
                return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://css.kwe.com/WaybillTracking.jsp'), $trackNumber, [
                    RequestOptions::COOKIES => $jar
                ], function (ResponseInterface $response) use ($trackNumber) {
                    $dom = (new Dom())->loadStr($response->getBody()->getContents());
                    $this->commonData['destination'] = $dom->find('.label2')->find('tr', 5)->find('[name="tmode"]')->getAttribute('value');
                    $this->commonData['actual_weight'] = $dom->find('[name="actual_weight"]')->getAttribute('value');

                    $pid = $dom->find('[name="PID"]')->getAttribute('value');

                    return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://css.kwe.com/MilestoneWbLoginAction.do'), $trackNumber, [
                        RequestOptions::FORM_PARAMS => [
                            'PID' => $pid
                        ]
                    ]);
                });
            }
        );
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = (new Dom())->loadStr($response->getBody()->getContents());

        $checkpoints = $dom->find('[align="center"]')->find('[BGCOLOR="#FFFFFF"]');

        if ($checkpoints->count() === 0) {
            return false;
        }

        $statuses = [];
        foreach ($checkpoints as $checkpoint) {
            $dateTime = Carbon::parse(str_replace('/', '-', $checkpoint->find('TD', 2)->text()));
            $statuses[] = new Status([
                'title' => $checkpoint->find('TD', 1)->find('a')->text(),
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
                'location' => $checkpoint->find('TD', 0)->text(),
            ]);
        }

        $result = new Parcel();

        $result->destinationAddress = $this->commonData['destination'];
        $result->weightValue = $this->commonData['actual_weight'];
        $result->statuses = $statuses;

        return $result;
    }
}