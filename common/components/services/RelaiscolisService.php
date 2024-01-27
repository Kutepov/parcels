<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\ExtraField;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use yii\base\BaseObject;

class RelaisColisService extends BaseService implements CountryRestrictionInterface, ValidateTrackNumberInterface, ExtraFieldsInterface, AsyncTrackingInterface
{
    private const BRANDS = [
        'L9' => '1001 LITS',
        'K7' => '1001KDO',
        '1A' => '24MX/XLMOTO',
        'TS' => '3SUISSES',
        '3E' => '3SUISSES 2019',
        'VB' => '3SUISSES BELGIUM',
        '4M' => '4MURS',
        'AD' => 'ADIDAS',
        'AI' => 'AIGLE',
        'KE' => 'AKEO',
        54 => 'ALAPAGE',
        'ZX' => 'ALEX',
        'AG' => 'ALICE GARDEN',
        'A4' => 'ALIEXPRESS CAINIAO',
        'A9' => 'ALINEA',
        '2F' => 'ALLPHARMA',
        '2X' => 'ALTEREGO DESIGN',
        'AM' => 'AMAZON',
        '1E' => 'ANASTORE.COM',
        'AN' => 'ANDRE',
        '1L' => 'ANTONIO PUIG',
        'ZM' => 'AOSOM / MH FRANCE',
        'A8' => 'ARKET',
        'AB' => 'ARMOIRE DE BEBE',
        'AZ' => 'ASOS',
        87 => 'ATLAS FOR MEN',
        '05' => 'ATOUT CONFORT',
        'PB' => 'ATRIUM SANTE',
        35 => 'AUBERT',
        '2U' => 'AUDIOTEL',
        '2C' => 'AUTOUR DE BEBE',
        'A6' => 'AVENUE DES PILES ',
        'AV' => 'AVOSDIM',
        'BG' => 'BABYGLOO',
        '04' => 'BALSAMIK',
        'B7' => 'BAMBINOU',
        'B1' => 'BANANAIR',
        'NG' => 'BANGGOOD',
        91 => 'BANSARD',
        '1C' => 'BEAUTE PRIVEE',
        'BN' => 'BEBE AU NATUREL',
        'QT' => 'BECQUET',
        'BE' => 'BENCH & BERG',
        'BQ' => 'BENOIST',
        'VX' => 'BESOLUX',
        '9B' => 'BEST MOUNTAIN',
        'BL' => 'BESTSELLER',
        'B3' => 'BHV',
        'B9' => 'BIERADELIS',
        'ZB' => 'BIZZBEE',
        'BW' => 'BLANC CERISE',
        '3P' => 'BLANCHEPORTE',
        'B6' => 'BLUEANGEL',
        '6M' => 'BOIS DESSUS BOIS DESSOUS',
        'BD' => 'BOITE A DESIGN',
        'QB' => 'BONI&SIDONIE',
        'BP' => 'BONPRIX',
        'BZ' => 'BOOKAAZ',
        82 => 'BOUYGUES TELECOM',
        29 => 'BOXTAL',
        'BR' => 'BRICE',
        'BV' => 'BRICO PRIVE',
        'VR' => 'BRUNO EVRARD',
        'BU' => 'BUT',
        34 => 'C LIVRE C PAYE',
        'UD' => 'C/O COUREON/MOMOX',
        '8C' => 'CALICOSY',
        'KM' => 'CAMAIEU',
        'KA' => 'CAMIF MATELSOM',
        'AY' => 'CAPITAINE MATELAS',
        '4C' => 'CARLA BIKINI',
        'KQ' => 'CARREFOUR CONFORT PALACE',
        'GV' => 'CARREFOUR GRANDS VINS',
        'VQ' => 'CARREFOUR JOUETS DE NOËL',
        'QD' => 'CARREFOUR LIVRES BY DECITRE',
        'HK' => 'CARREFOUR OPERATIONS',
        'KP' => 'CARREFOUR SUPPLY CHAIN',
        'FA' => 'CASPER',
        '4N' => 'CATIMINI',
        85 => 'CDISCOUNT',
        'GP' => 'CDISCOUNT PAR DPI',
        'HP' => 'CHIC DES PLANTES !',
        '2Z' => 'CLEAN INNOVATION',
        'L1' => 'C-LOG',
        '2S' => 'COMPAGNIE DES SENS (TOOPOST)',
        'D5' => 'COMPTOIR DES LITS',
        43 => 'CONFORAMA',
        40 => 'CONSOGLOBE',
        'ND' => 'CONTROLSOUND',
        'YM' => 'CORA',
        'A5' => 'COS',
        'FG' => 'COTE FEELGOOD',
        'L5' => 'COTE LUMIERE',
        'WC' => 'CREALITERIE',
        'LC' => 'CROQUETTELAND',
        'L2' => 'CROSSLOG INTERNATIONAL',
        'BH' => 'CTI',
        '6C' => 'CYCLEON',
        '03' => 'CYRILLUS S.A.S.',
        'FQ' => 'DA CONFORT',
        'HS' => 'DALI & SCHUSTER',
        'DA' => 'DARTY',
        '06' => 'DAXON',
        'D3' => 'DBWEB',
        19 => 'DECITRE',
        'DD' => 'DECLIKDECO',
        'WM' => 'DECOCLICO',
        '1R' => 'DELAMAISON',
        '6D' => 'DESIGN BESTSELLER',
        52 => 'DESMARQUESETVOUS',
        'VC' => 'DEVO CONCEPT',
        'DH' => 'DHL INTERNATIONAL EXPRESS FRANCE',
        65 => 'DHL PARCEL',
        '2W' => 'DIIIZ',
        59 => 'DISTRI-ENTREPRISE',
        '4D' => 'DOS ET SOMMEIL',
        'DP' => 'DPI',
        'DW' => 'DRAWER',
        'LR' => 'E LECLERC',
        'GA' => 'EDREAMGARDEN',
        'MQ' => 'EMINENCE',
        'LS' => 'EMMA MATELAS',
        'FR' => 'ENVIE DE FRAISE',
        '4E' => 'ESSE',
        'TM' => 'ETAM',
        'VM' => 'EVE MATELAS',
        'XE' => 'EXPERIENCE LOGISTICS',
        'FC' => 'FEERIE CAKE',
        'FL' => 'FILE DANS TA CHAMBRE',
        'FB' => 'FLEXLAB',
        45 => 'FNAC LOGISTIQUE',
        'FS' => 'FRANCOISE SAGET',
        'FD' => 'FREMAUX DELORME',
        'FH' => 'FRENCHROSA',
        'FF' => 'FROM FUTURE',
        'GC' => 'GALERIE CHIC',
        'GL' => 'GALERIES LAFAYETTE',
        'GH' => 'GALERIES LAFAYETTE HAUSSMANN ',
        'GM' => 'GARDEN IMPRESSIONS',
        14 => 'GIFI',
        'G8' => 'GOOD GOUT',
        'GZ' => 'GREENWEEZ',
        'GS' => 'GUESS',
        'YG' => 'GUY DEMARLE',
        'HZ' => 'H&M',
        '2E' => 'HARDWARE.FR',
        'HW' => 'HELIOSWEB',
        'HI' => 'HILDING ANDERS',
        'S9' => 'HM LITERIE ',
        'HB' => 'HOME BAIN',
        'HM' => 'HOME MAISON',
        '3C' => 'HOME MAISON2',
        'HF' => 'HOMIFAB',
        'HT' => 'HOTSQUASH',
        'D7' => 'ID LITERIE',
        'LZ' => 'ILOBED',
        '2K' => 'IMATEL / LOGVAD',
        'NK' => 'IMMUNOCTEM',
        'N1' => 'INNOVAXE',
        'T7' => 'I-TOU',
        'DI' => 'JACADI',
        46 => 'JACQUART',
        '3L' => 'JOLICILS',
        'AK' => 'JONAK',
        'NP' => 'JPL TEXTILES',
        'UL' => 'JULES',
        'KL' => 'KAY LARGO LOGISTICS',
        'KB' => 'KEBELLO',
        'KT' => 'KING OF COTTON ',
        'KZ' => 'KIPLI',
        'KK' => 'KOOKAI',
        'K8' => 'KREABEL',
        'K5' => 'KSERVICES',
        'LH' => 'LA FOURCHE',
        'GF' => 'LA GENTLE FACTORY',
        '08' => 'LA MAISON DE VALERIE',
        'LN' => 'LA MALLE D ASIE',
        'RQ' => 'LA REBOUCLE',
        '01' => 'LA REDOUTE',
        18 => 'LA REDOUTE ESPAGNE',
        32 => 'LA REDOUTE PORTUGAL',
        'HE' => 'LABORATOIRE HEVEA',
        'LL' => 'LAMALOLI',
        48 => 'LDLC',
        'AX' => 'LE MATELAS',
        '5M' => 'LE MATELAS 365',
        'LF' => 'LES FURETS DU NORD BY DECITRE',
        'LW' => 'LEVIS',
        73 => 'LINVOSGES',
        'LD' => 'LITERIE DE PARIS',
        'L4' => 'LOBERON',
        'LG' => 'LOVE & GREEN',
        'XB' => 'LPB WOMAN ',
        'LU' => 'LUTECE BIKE',
        'BA' => 'MA BIERE ARTISANALE',
        'QL' => 'MA PTITE CULOTTE',
        'MD' => 'MADE IN DESIGN',
        'MA' => 'MADURA',
        'GI' => 'MAGIC PC',
        'LX' => 'MAGIC PECHE',
        'MN' => 'MAGINEA',
        'TN' => 'MAISON 123',
        '3I' => 'MAISON DE LA LITERIE',
        'MY' => 'MAISON ET STYLES',
        'MR' => 'MALITERIE',
        '2M' => 'MANO MANO',
        '4B' => 'MARIONNAUD',
        '1V' => 'MATEFLEX',
        'MP' => 'MATELAS EXPRESS',
        26 => 'MATERIEL.NET',
        '2V' => 'MATRASKIL',
        '4T' => 'MEERT TRADITION',
        'D6' => 'MEUBLES ET DESIGN',
        'MO' => 'MISSEGLE',
        41 => 'MIZUNO CORPORATION FRANCE',
        'DX' => 'MODZ',
        'RM' => 'MONCANAPE',
        'N2' => 'MONKI',
        'XM' => 'MONTELONE',
        'MW' => 'MWH OUTDOOR SELECTION',
        'KX' => 'MY HOME DELIVERY',
        'DG' => 'MY MATELAS',
        16 => 'MYPIX CEWE',
        'NN' => 'NAF NAF',
        'NT' => 'NATILOO',
        94 => 'NESPRESSO',
        'NB' => 'NOCIBE',
        'NR' => 'NORAUTO',
        'WE' => 'OCS WORLDWIDE',
        'KD' => 'OKAIDI',
        'M8' => 'OPS',
        'SC' => 'OSCARO',
        '2T' => 'OTTOMOBILE',
        '02' => 'OXYBUL EVEIL & JEUX',
        'PA' => 'PARASHOP',
        61 => 'PARTYLITE',
        '3M' => 'PATATAM',
        38 => 'PEARL DIFFUSION',
        '6P' => 'PERTEMBA GLOBAL',
        '2N' => 'PETITE AMELIE',
        '4P' => 'PHARMA41',
        96 => 'PICWICTOYS',
        'PE' => 'PIER IMPORT PAR DPI',
        'PK' => 'PLANET PUZZLES',
        'M5' => 'POINT MATELAS',
        'PP' => 'POMMPOIRE',
        '5P' => 'POST11',
        'BK' => 'PREMIUM SPORT',
        'M7' => 'PROMO MATELAS',
        '2P' => 'PROMOD',
        'QM' => 'QUOTIDOM',
        'RA' => 'RAVIDAY',
        28 => 'RCB',
        83 => 'RCS',
        'RN' => 'REBOUND',
        'RL' => 'RECYCLIVRE',
        'CC' => 'RELAIS COLIS CTOC',
        'RD' => 'RENDEZ-VOUS DECO',
        'R6' => 'RITCHIE JEANS',
        'R7' => 'RUE DE LA DECO',
        53 => 'RUE DU COMMERCE',
        'S4' => 'SABON',
        'PC' => 'SANTE MOINS CHERE',
        '4S' => 'SCIEM',
        '2Y' => 'SENSEI MAISON',
        'S8' => 'SERGENT MAJOR',
        'R2' => 'SFR TV',
        'SH' => 'SHOES',
        24 => 'SHOE-STYLE.FR',
        'SL' => 'SHOPRUNBACK',
        'SP' => 'SHOWROOMPRIVE.COM',
        'S1' => 'SIB OUEST',
        'SW' => 'SIMBA SLEEP',
        'SM' => 'SMALLABLE',
        '5S' => 'SMART LINE FURNITURE 24',
        'SF' => 'SO FACTORY',
        '1K' => 'SOFRAMA',
        'SX' => 'SOLAGE',
        'S7' => 'SOMMEIL DE PLOMB',
        'SR' => 'SPARTOO',
        'S6' => 'STORIES',
        '6S' => 'SUD EXPRESS',
        93 => 'TATI',
        'L6' => 'THE AGENT',
        69 => 'THE OTHER STORE',
        70 => 'TNT EXPRESS NATIONAL',
        'TX' => 'TOILINUX',
        'T2' => 'TOLEDANO ERTEX',
        'T1' => 'TOLEDANO MODALITA',
        'T3' => 'TOLEDANO TCP',
        55 => 'TOP ACHAT',
        'T5' => 'TOP_ACHAT',
        'TG' => 'TRED E LOG',
        'TC' => 'TREND CORNER',
        'TP' => 'TRIUMPH',
        'UX' => 'UJ',
        'TA' => 'UN AMOUR DE TAPIS',
        'UN' => 'UNDIZ',
        'PM' => 'UNE PETITE MOUSSE',
        'UQ' => 'UNIQLO',
        'UA' => 'UNIVERS DU SOMMEIL',
        'UP' => 'UPELA',
        'UW' => 'U-WEB',
        78 => 'VEEPEE',
        '07' => 'VERTBAUDET',
        'VI' => 'VINCI AUTOROUTES',
        'VD' => 'VINTED',
        'WU' => 'VITALIT',
        'VS' => 'VOSHOES',
        'VN' => 'VRACNROLL',
        89 => 'WANIMO',
        'WR' => 'WEBER INDUSTRIES',
        'WT' => 'WEBTOB LITERIE',
        'WK' => 'WEEKDAY',
        'WW' => 'WESTWING',
        'WN' => 'WESTWINGNOW',
        'WL' => 'WOLF LINGERIE',
        'WB' => 'WOODBRASS',
        13 => 'YAKAROULER',
        'YS' => 'YSE',
        'ZC' => 'ZACK',
        'ZR' => 'ZARA'
    ];
    public $id = 280;

    public static function validateTrackNumber($trackNumber)
    {
        return true;
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dataJson = json_decode($data);

        $result = new Parcel();

        foreach ($dataJson->Colis->Colis->ListEvenements->Evenement as $checkpoint) {
            $date = Carbon::parse($checkpoint->Date);

            $result->statuses[] = new Status([
                'title' => $checkpoint->Libelle,
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);

        }

        return (!empty($result->statuses)) ? $result : false;
    }

    private function request($trackNumber, $extraFields = [])
    {
        $brand = array_search($extraFields['extra_shop'],  self::BRANDS);
        if (false === $brand) {
            return false;
        }

        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.relaiscolis.com/suivi-de-colis/index/tracking/'), $trackNumber, [
            RequestOptions::FORM_PARAMS => [
                'codeEnseigne' => $brand,
                'valeur' => substr($trackNumber, 2, -2),
                'typeRecherche' => 'EXP',
                'nomClient' => substr($extraFields['extra_recipient_name'], 0, 4),
            ]
        ]);
    }

    public function trackAsync($trackNumber, $extraFields = []): PromiseInterface
    {
        return $this->request($trackNumber);
    }

    public function track($trackNumber, $extraFields = [])
    {
        return $this->request($trackNumber, $extraFields)->wait();
    }


    public function restrictCountries()
    {
        return ['fr', 'nl', 'be', 'lt', 'ch'];
    }

    public function trackNumberRules(): array
    {
        return ['[A-Z]{2}\d{12}']; //BP000803202401
    }

    public function extraFields()
    {

        return [
            new ExtraField([
                'type' => ExtraField::TYPE_TEXT,
                'name' => 'extra_recipient_name',
                'placeholder' => 'Nom du destinataire',
                'shortTitle' => 'Nom du destinataire',
                'mask' => null,
                'field_regexp' => '.*?',
                'validateRegexp' => '^((?!\s*$).+){3,}',
                'delete_regexp' => null,
                'error' => \t('Заполните поле')
            ]),

            new ExtraField([
                'type' => ExtraField::TYPE_DROPDOWN,
                'name' => 'extra_shop',
                'field_regexp' => '^((?!\s*$).+){3,}',
                'placeholder' => 'Indiquez votre enseigne',
                'values' => self::BRANDS,
                'error' => \t('Заполните поле')
            ])
        ];
    }

    public function extraFieldsTestValues(): array
    {
        return [
            'extra_recipient_name' => 'CELLI',
            'extra_shop' => 'BONPRIX'
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