<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\ExtraField;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Psr7\Request;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Options;
use Psr\Http\Message\ResponseInterface;

class NacexService extends BaseService implements
    ValidateTrackNumberInterface,
    PriorValidateTrackNumberInterface,
    ManuallySelectedInterface,
    ExtraFieldsInterface
{
    public $id = 261;

    public static function validateTrackNumber($trackNumber)
    {
        return preg_match('#^\d\d{7,8}$#', $trackNumber);
    }

    public function track($trackNumber, $extraFields = [])
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://www.nacex.es/seguimientoDetalle.do?agencia_origen=' . $extraFields['zip'] .
            '&numero_albaran=' . $trackNumber .
            '&estado=1&internacional=0&externo=N&usr=null&pas=null&idioma=es'
        ), $trackNumber, [

        ], function (ResponseInterface $response) {
            $body = $response->getBody()->getContents();
            $body = utf8_encode($body);

            if (stripos($body, 'There are no delivery notes entered into the system that comply with the specified criteria') !== false) {
                return false;
            }

            $options = new Options();
            $options->setCleanupInput(false);
            $dom = (new Dom())->loadStr($body, $options);

            $result = new Parcel();

            foreach ($dom->find('#estados_envio', 0)->find('tbody', 0)->find('tr') as $checkpoint) {
                $date = Carbon::parse(str_replace('/', '.', $checkpoint->find('td', 0)->text) . ' ' . $checkpoint->find('td', 1)->text);
                $result->statuses[] = new Status([
                    'title' => trim(html_entity_decode(strip_tags($checkpoint->find('td', 2)->innerHTML)), " \s\n\r\t"),
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                ]);
            }

            $extraInfoLabels = [
                'Agencia origen',
                'Número albarán',
                'Fecha albarán',
                'Referencia',
                'Servicio',
                'Número de bultos',
                'Firma/sello'
            ];

            foreach ($extraInfoLabels as $label) {
                if (preg_match('#<p class="t4 nx-no-margin nx-fg-gray2">' . $label . '</p>[\s\t\r]+<p class="t8 nx-no-margin nx-fg-black">(.*?)</p>#siu', $body, $m)) {
                    $result->extraInfo[$label] = trim($m[1]);
                }
            }

            return $result;
        })->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '\d\d{7,8}' // 37227680, zip: 7447
        ];
    }

    public function priorTrackNumberRules(): array
    {

        return [
            '\d\d{7,8}'
        ];
    }

    /**
     * @return array
     */
    public function restrictCountries()
    {
        return [
            'es'
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

    public function extraFields()
    {
        return [
            new ExtraField([
                'type' => ExtraField::TYPE_TEXT,
                'name' => 'zip',
                'placeholder' => 'Office',
                'title' => 'Office',
                'shortTitle' => 'Office',
                'mask' => null,
                'field_regexp' => '^\d{8}$',
                'validateRegexp' => '^[A-Z0-9]{4,5}$',
                'delete_regexp' => null,
                'error' => \t('Неверный индекс получателя')
            ])
        ];
    }
}