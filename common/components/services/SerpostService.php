<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\ExtraField;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class SerpostService extends BaseService implements ValidateTrackNumberInterface, ExtraFieldsInterface, AsyncTrackingInterface
{
    public $id = 402;
    private $commonInformation;

    public static function validateTrackNumber($trackNumber)
    {
        return true;
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dataJson = json_decode($data, true);

        $result = new Parcel();

        foreach ($dataJson['d'] as $checkpoint) {
            $date = Carbon::parse(str_replace('/', '-', $checkpoint['RetornoCadena3']));
                $result->statuses[] = new Status([
                'title' => $checkpoint['RetornoCadena4'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);

        }

        return (!empty($result->statuses)) ? $result : false;
    }

    private function request($trackNumber, $extraFields = [])
    {
        $postData = [
            RequestOptions::BODY => json_encode(['Anio' => $extraFields['cboAnio'], 'Tracking' => $trackNumber]),
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json; charset=UTF-8',
            ],
        ];
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://clientes.serpost.com.pe/prj_online/Web_Busqueda.aspx/Consultar_Tracking'), $trackNumber, $postData,
            function (ResponseInterface $response) use ($trackNumber, $extraFields, $postData) {
                $this->commonInformation = json_decode($response->getBody()->getContents(), true);

                if ($this->commonInformation['d'] === null) {
                    return false;
                }

                return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://clientes.serpost.com.pe/prj_online/Web_Busqueda.aspx/Consultar_Tracking_Detalle_IPS'), $trackNumber, $postData);
            });
    }

    public function trackAsync($trackNumber, $extraFields = []): PromiseInterface
    {
        return $this->request($trackNumber);
    }

    public function track($trackNumber, $extraFields = [])
    {
        return $this->request($trackNumber, $extraFields)->wait();
    }


    public function trackNumberRules(): array
    {
        return ['[A-Z]{2}\d{9}[A-Z]{2}']; //RU916287513NL
    }

    public function extraFields()
    {
        return [
            new ExtraField([
                'type' => ExtraField::TYPE_DROPDOWN,
                'name' => 'cboAnio',
                'placeholder' => 'Año del Envío',
                'error' => \t('Заполните поле'),
                'values' => [2020 => 2020, 2021 => 2021]
            ])
        ];
    }

    public function extraFieldsTestValues(): array
    {
        return [
            'cboAnio' => 2021,
        ];
    }


    public function extraFieldsTipApp()
    {
        return \t('Чтобы отследить посылку вам необходимо ввести дополнительную информацию.');
    }

    public function extraFieldsTip()
    {
        return \t('Чтобы отследить посылку вам необходимо ввести дополнительную информацию.');
    }
}