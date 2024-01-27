<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class JETLogisticsKZService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{

    private const MONTHS = [
        'января' => 1,
        'февраля' => 2,
        'марта' => 3,
        'апреля' => 4,
        'майя' => 5,
        'июня' => 6,
        'июля' => 7,
        'августа' => 8,
        'сентября' => 9,
        'октября' => 10,
        'ноября' => 11,
        'декабря' => 12,
    ];


    public $id = 460;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.jet.com.kz/ajax/checkstatus.php'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'TID' => $trackNumber,
                'check_pay' => 'true'
            ]
        ]);
    }


    public function trackNumberRules(): array
    {
        return [
            '[0-9]{7, 12}', //1070054497 0137793 008031085097
        ];
    }

    public function parseResponse($response, $trackNumber)
    {
        $json = json_decode($response->getBody()->getContents(), true);

        if ($json['success'] !== 1) {
            return false;
        }

        $items = explode("\r", $json['answer']);

        $result = new Parcel();

        foreach ($items as $item) {

            if (stripos($item, 'Груз выдан') !== false) {
                $statusDate = explode(' : ', $item);
                [$day, $month, $year] = explode(' ', $statusDate[1]);
                $dateTime = Carbon::parse($day . '-' . self::MONTHS[$month] . '-' . mb_substr($year, 0, -3));

                $result->statuses[] = new Status([
                    'title' => $statusDate[0],
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute')
                ]);

                continue;
            }

            $statusDate = explode('), дата выполнения : ', $item);
            if (isset($statusDate[1]) && $dateTime = \DateTime::createFromFormat('d.m.Y H:i:s', $statusDate[1])) {
                $statusLocation = explode('-(', $statusDate[0]);
                $dateTime = new Carbon($dateTime);
                $result->statuses[] = new Status([
                    'title' => $statusLocation[0],
                    'location' => $statusLocation[1],
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute')
                ]);
            }
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'kz',
            'ru',
        ];
    }

}