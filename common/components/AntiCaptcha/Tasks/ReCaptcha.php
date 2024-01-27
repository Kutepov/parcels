<?php namespace common\components\AntiCaptcha\Tasks;

use yii\base\Model;

class ReCaptcha extends Model
{
    public $type = 'NoCaptchaTaskProxyless';
    public $websiteURL;
    public $websiteKey;
    public $minScore;
}