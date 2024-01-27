<?php namespace common\components\services\models;

use yii\base\Model;

/**
 * Class Service
 * @package common\components\services\models
 *
 * @property string $destinationCountryCode    Двубуквенный код страны назначения
 * @property string $departureCountryCode      Двубуквенный код страны отправки
 * @property string $destinationCountry    Страна назначения
 * @property string $departureCountry      Страна отправки
 * @property string $departureAddress       Адрес получателя
 * @property string $destinationAddress     Адрес отправителя
 * @property string $estimatedDeliveryTime  Расчетное время доставки (unix timestamp)
 * @property int $weight                    Вес посылки (переводить килограммы в граммы)
 * @property string $weightValue               Вес посылки (готовая строка с единицей измерения)
 * @property int $weightUnit                Еденица измерения веса (например: LBS). Задавать, только если отличается от килограммов
 * @property Status[] $statuses             Статусы посылки
 * @property string[] $extraTrackNumbers       Дополнительные трек-номера посылки
 * @property string $sender                 Отправитель
 * @property string $senderPhone            Телефон отправителя
 * @property string $description            Описание груза
 * @property string $recipient              Получатель
 * @property string $payer                  Плательщик
 * @property string $cost                   Стоимость посылки (вместе с валютой)
 * @property string $price                  Объявленная стоимость посылки
 * @property string $paymentMethod          Способ оплаты посылки
 *
 * @property string $statusesHash
 */
class Parcel extends Model
{
    public $destinationCountryCode;
    public $destinationCountry;
    public $departureCountryCode;
    public $departureCountry;
    public $departureAddress;
    public $destinationAddress;
    public $estimatedDeliveryTime;
    public $weight;
    public $weightUnit;
    public $weightValue;
    public $sender;
    public $senderPhone;
    public $description;
    public $recipient;
    public $price;
    public $cost;
    public $paymentMethod;
    public $payer;
    public $statuses = [];
    public $extraTrackNumbers = [];
    public $orderNumber;
    public $extraInfo = [];

    public function getStatusesHash()
    {
        if (!$this->statuses) {
            return null;
        }

        return md5(
            implode('', array_map(static function ($v) {
                    return implode('.', $v);
                }, array_map(static function ($v) {
                        return array_map('md5', $v->attributes);
                    }, $this->statuses)
                )
            )
        );
    }
}