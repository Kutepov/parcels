<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use yii;

class MeestExpressService extends BaseService implements ValidateTrackNumberInterface, PriorValidateTrackNumberInterface, ComplicatedAsyncTrackingInterface
{
    public $id = 19;
    private $url = 'https://www.meest-express.com.ua/tracking';
    private $trackData;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://t.meest-group.com/n/'), $trackNumber, [],
            function (ResponseInterface $response) use ($trackNumber) {
                if (!preg_match("#var salt = '(.*?)';#si", $response->getBody()->getContents(), $m)) {
                    throw new yii\web\BadRequestHttpException();
                }

                return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://t.meest-group.com/get.php?what=tracking&number=' . $trackNumber . '&ext_track=&chk=' . md5($m[1] . $trackNumber . $m[1])), $trackNumber, [
                    RequestOptions::HEADERS => [
                        'Referer' => 'https://t.meest-group.com/',
                        'Accept' => 'application/xml, text/xml, */*; q=0.01'
                    ]
                ], function (ResponseInterface $response) use ($trackNumber) {
                    $response = json_decode(json_encode(simplexml_load_string($response->getBody()->getContents())), true);
                    if (!isset($response['result_table']['items'])) {
                        return false;
                    }
                    else {
                        $this->trackData = $response;
                        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://t.meest-group.com/get.php?what=tracking_ext_v3&out=json&lang=ua&number=' . $response['result_table']['items'][0]['ShipmentIdRef']), $trackNumber, [
                            self::EXTRA_INFO => $response
                        ]);
                    }
                });
            });
    }

    public function parseResponse($response, $trackNumber, $extraInfo = [])
    {
        $parcelInfo = yii\helpers\Json::decode($response->getBody()->getContents());
        $checkpoints = $this->trackData['result_table']['items'];

        list ($from, $to) = explode(' - ', $parcelInfo['t_info_route']);
        $result = new Parcel([
            'weightValue' => $parcelInfo['t_info_weight'],
            'departureAddress' => $from,
            'destinationAddress' => $to,
            'extraInfo' => [
                'Документи для отримання' => str_replace('<br>', ' ', $parcelInfo['t_info_documents']),
                'Умови доставки' => $parcelInfo['t_info_delivery']
            ]
        ]);

        if (!count($checkpoints)) {
            return false;
        }

        $shipmentId = '';
        foreach ($checkpoints as $k => $checkpoint) {
            $location = [];
            if (is_string($checkpoint['Country_RU'])) {
                $location[] = mb_ucfirst(mb_strtolower($checkpoint['Country_RU'] ?: null));
            }
            if (is_string($checkpoint['City_RU'])) {
                $location[] = $checkpoint['City_RU'] ?: null;
            }


            if (!$k) {
                $result->departureAddress = implode(', ', $location);
            }

            $date = Carbon::parse($checkpoint['DateTimeAction']);

            $result->statuses[] = new Status([
                'title' => mb_ucfirst($this->processStatus($checkpoint['ActionMessages_RU'])),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => implode(', ', $location)
            ]);

            if ($checkpoint['ShipmentIdRef'] != '0x03000000000000000000000000000000' && !$shipmentId) {
                $shipmentId = $checkpoint['ShipmentIdRef'];
            }
        }

        return $result;
    }

    private function processStatus($status): string
    {
        $status = htmlspecialchars_decode($status);
        $status = str_replace('<br>', ' - ', $status);
        $status = trim($status, " -\t\n\r");
        $status = preg_replace('#ДОРУЧЕНО<br>[\d]{2}\.[\d]{2}\.[\d]{4}#iu', 'Доручено', $status);
        $status = mb_strtolower($status, 'UTF-8');
        $status = mb_ucfirst($status);

        return $status;
    }

    public function priorTrackNumberRules(): array
    {
        return [
            '6150\d{12}'
        ];
    }

    public function trackNumberRules(): array
    {
        return [
            '6150\d{12}',
            'CV[0-9]{9}[A-Z]{2}',
            'EQTWL[0-9]{10}YQ',
            'MGRMY[0-9]{10}YQ',
            '[A-Z]{2}[0-9]{7}[A-Z]{2}[0-9]{5}G',
            'SV[0-9]{5}'
        ];
    }
}