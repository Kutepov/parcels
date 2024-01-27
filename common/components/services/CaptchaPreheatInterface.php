<?php namespace common\components\services;

use common\models\redis\PreheatedCaptcha;
use common\models\redis\Recaptcha;
use yii\base\BaseObject;

interface CaptchaPreheatInterface
{
    /**
     * @return Recaptcha|PreheatedCaptcha|null
     */
    public function preheatCaptcha();

    public function captchaLifeTime(): int;

    /**
     * @return null|int
     */
    public function recaptchaVersion();

    public function maxPreheatProcesses();
}