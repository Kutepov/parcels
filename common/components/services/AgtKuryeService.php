<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\ExtraField;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\redis\Recaptcha;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use yii\base\BaseObject;

class AgtKuryeService extends BaseService implements CountryRestrictionInterface, ValidateTrackNumberInterface, ExtraFieldsInterface, AsyncTrackingInterface
{
    private const SHOPS = [
        12069 => '1 Milyon Kitap',
        12049 => 'Akbank',
        12082 => 'AKSİGORTA A.Ş.',
        12074 => 'Albaraka Türk Katılım Bankası',
        11933 => 'Alternatif Bank',
        12047 => 'Amazon',
        11984 => 'Watsons',
        3563 => 'AŞG',
        12119 => 'BRIGHSTAR',
        12057 => 'Bulduysan',
        11958 => 'CEPTETEB',
        11977 => 'Çikolata Sepeti',
        12020 => 'Defacto',
        1107 => 'DenizBank',
        11991 => 'E-Hamal.com',
        12076 => 'Emlak Katılım Bankası',
        3480 => 'QNB Finansbank',
        12026 => 'Evidea',
        12046 => 'Flo',
        11910 => 'Garanti',
        12117 => 'Global Menkul Değerler',
        12067 => 'Gratis',
        12068 => 'GratisT',
        10334 => 'Halk Bankası',
        10480 => 'Hepsiburada',
        10289 => 'HSBC',
        9927 => 'İş Bankası',
        12091 => 'Kahve Dünyası',
        12029 => 'Karaca Home',
        12110 => 'kitaplarimgeldi.com',
        12066 => 'Kitapyurdu',
        12141 => 'Kokopelli Şehirde',
        12001 => 'Koton',
        8248 => 'Kuveyt Türk',
        12113 => 'Makbul Kuruyemiş',
        11974 => 'Mavi',
        12140 => 'Midas Menkul Değerler',
        12018 => 'Modanisa',
        12096 => 'Momento Kart',
        11969 => 'Odeabank',
        12075 => 'Oyak Yatırım Menkul Değerler',
        12021 => 'Papara',
        12062 => 'Penti',
        12121 => 'PEP Kart',
        12035 => 'Samsung',
        12027 => 'Sütaş',
        12048 => 'Toyzz Shop',
        12010 => 'Trendyol',
        6812 => 'Tübitak Bilgem KamuSM',
        12063 => 'Türk Hava Yolları',
        12104 => 'Türk Telekom',
        8043 => 'Türkiye Finans',
        11987 => 'Vakıf Katılım',
        10835 => 'Yapı Kredi Bankası',
        7976 => 'Ziraat Bankası'
    ];
    public $id = 281;

    public static function validateTrackNumber($trackNumber)
    {
        return true;
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        $dom = (new Dom())->loadStr($data);
        $result = new Parcel();

        foreach ($dom->find('#progress-bar', 0)->find('.circle') as $checkpoint) {
            $dateTime = Carbon::parse($dom->find('.lead', 2)->text);
            $title = $checkpoint->find('.status', 0)->text;
            if ($title) {
                $result->statuses[] = new Status([
                    'title' => $title,
                    'date' => $dateTime->timestamp,
                    'dateVal' => $dateTime->toDateString(),
                    'timeVal' => $dateTime->toTimeString('minute'),
                ]);
            }
        }
        return (!empty($result->statuses)) ? $result : false;

    }

    public function trackAsync($trackNumber, $extraFields = []): PromiseInterface
    {
        return $this->request($trackNumber, $extraFields);
    }

    public function track($trackNumber, $extraFields = [])
    {
        return $this->request($trackNumber, $extraFields)->wait();
    }

    private function request($trackNumber, $extraFields)
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://takipagt.aktif.com/'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'QueryShipmentMV.Client' => array_search($extraFields['extra_shop'], self::SHOPS),
                'QueryShipmentMV.SurnameFirst2' => $extraFields['extra_recipient_name'],
                'QueryShipmentMV.ShipmentNo' => $trackNumber,
            ]
        ]);
    }


    public function restrictCountries()
    {
        return ['tr'];
    }

    public function trackNumberRules(): array
    {
        return ['\d{12}']; //121051232561
    }

    public function extraFields()
    {
        return [
            new ExtraField([
                'type' => ExtraField::TYPE_TEXT,
                'name' => 'extra_recipient_name',
                'placeholder' => 'Soyadınızın ilk 2 harfi',
                'shortTitle' => 'Soyadınızın ilk 2 harfi',
                'mask' => null,
                'field_regexp' => '.*?',
                'validateRegexp' => '^\w{2}$',
                'delete_regexp' => null,
                'error' => \t('Заполните поле')
            ]),
            new ExtraField([
                'type' => ExtraField::TYPE_DROPDOWN,
                'name' => 'extra_shop',
                'field_regexp' => '.*?',
                'placeholder' => 'Müşteri Seçiniz',
                'values' => self::SHOPS,
                'error' => \t('Заполните поле')
            ])
        ];
    }

    public function extraFieldsTestValues(): array
    {
        return [
            'extra_shop' => 'Papara',
            'extra_recipient_name' => 'Ak'
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