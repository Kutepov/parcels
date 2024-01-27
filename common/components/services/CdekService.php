<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use stdClass;

class CdekService extends BaseService implements
    ValidateTrackNumberInterface,
    AsyncTrackingInterface,
    CountryRestrictionInterface
{
    public $id = 39;
    public $api = true;
    public $mainAsyncCourier = true;

    private const MAPPING = [1, 2, 3, 4, 5, 6, 7, 8, 9, 0, 'A', 'B', 'C', 'D', 'E', 'F'];

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://mobile-apps.cdek.ru/api/v2/order/' . $trackNumber, [
            RequestOptions::HEADERS => [
                'Accept' => '*/*',
                'Connection' => 'keep-alive',
                'x-device-id' => $this->generateDeviceIdByTrackNumber($trackNumber),
                'Accept-Encoding' => 'gzip;q=1.0, compress;q=0.5',
                'User-Agent' => 'CDEK/3.18.1 (iOS/15.1.0; com.cdek.cdekapp; build/62)',
                'Accept-Language' => 'ru-RU',
                'X-User-Lang' => 'ru',
                'X-User-Timezone' => 'Europe/Moscow',
            ]
        ]), $trackNumber);
    }

    private function generateDeviceIdByTrackNumber(string $trackNumber): string
    {
        $hash = md5($trackNumber);
        return substr($hash, 0, 8) . '-' .
            substr($hash, 8, 4) . '-' .
            substr($hash, 12, 4) . '-' .
            substr($hash, 16, 4) . '-' .
            substr($hash, 20, 12);
    }

    /**
     * @param Response|stdClass $response
     * @param string $trackNumber
     * @return Parcel|bool
     */
    public function parseResponse($response, $trackNumber)
    {
        $response = json_decode($response->getBody()->getContents());

        if (!$response->number) {
            return false;
        }

        $result = new Parcel([
            'departureAddress' => $response->departureCity->name,
            'destinationAddress' => $response->destinationCity->name,
            'weight' => $response->additionalInfo->realWeight * 1000,
            'extraInfo' => [
                'Срок бесплатного хранения' => $response->storageDateEnd,
                'Вид отправления' => $response->additionalInfo->description,
                'Количество мест' => $response->additionalInfo->numberOfPlaces
            ]
        ]);

        if (isset($response->plannedDeliveryDate) && !empty($response->plannedDeliveryDate)) {
            $result->estimatedDeliveryTime = strtotime($response->plannedDeliveryDate);
        }

        if (isset($response->steps)) {
            foreach ($response->steps as $step) {
                $city = $step->city;
                foreach ($step->statuses as $checkpoint) {
                    $date = Carbon::parse($checkpoint->date);
                    $result->statuses[] = new Status([
                        'title' => $checkpoint->title,
                        'location' => $city,
                        'date' => $date->timestamp,
                        'dateVal' => $date->toDateString(),
                        'timeVal' => $date->toTimeString('minute')
                    ]);
                }
            }
        }
        elseif ($response->status === 'INVOICE_CREATED') {
            $date = Carbon::parse($response->departureDate);
            $result->statuses[] = new Status([
                'title' => 'Создана накладная, ожидает прихода груза от отправителя',
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }

        return $result;
    }

    public function trackNumberRules(): array
    {
        return [
            '\d{10}'
        ];
    }

    public function restrictCountries()
    {
        return [
            'ru'
        ];
    }
}