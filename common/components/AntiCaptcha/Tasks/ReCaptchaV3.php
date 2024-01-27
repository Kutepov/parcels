<?php namespace common\components\AntiCaptcha\Tasks;

use yii\base\Model;

class ReCaptchaV3 extends ReCaptcha
{
    public $type = 'RecaptchaV3TaskProxyless';
    public $minScore = '0.9';
    public $pageAction;
}