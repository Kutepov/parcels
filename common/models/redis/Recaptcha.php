<?php namespace common\models\redis;


use yii\base\Model;

/**
 * Class Recaptcha
 * @package common\components\models\redis
 *
 * @property int $id
 * @property int $provider_id
 * @property string $token
 * @property int $expires_at
 */
class Recaptcha extends Model
{
    public $token;

    public static function findTokenForProvider($id)
    {
        return null;
    }
}