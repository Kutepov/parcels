<?php namespace common\models\redis;

use yii\base\Model;

/**
 * Class Recaptcha
 * @package common\components\models\redis
 *
 * @property int $id
 * @property int $provider_id
 * @property string $answer
 * @property string $cookies
 * @property int $expires_at
 */
class PreheatedCaptcha extends Model
{
    public $answer;
    public $cookies;
    public $expires_at;

    /**
     * @param $id
     * @return array|self|null
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     */
    public static function findForProvider($id)
    {
        return null;
    }
}