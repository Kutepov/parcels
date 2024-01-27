<?php namespace common\components\services\models;

use yii\base\Model;

/**
 * Class Status
 * @package common\components\services\models
 *
 * @var string $title Текст статуса
 * @var int $date Дата статуса (Unix timestamp)
 * @var string $dateVal Дата статуса в формате YYYY-MM-DD
 * @var string $timeVal Время статуса в формате HH:II
 * @var int $timezoneVal Часовой пояс статуса +3 или -5
 * @var string $location Место (локация) статуса
 */
class Status extends Model
{
    public $title;
    public $date;
    public $location;

    public $dateVal;
    public $timeVal;
    public $timezoneVal;
}