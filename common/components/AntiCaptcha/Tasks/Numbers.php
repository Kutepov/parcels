<?php namespace common\components\AntiCaptcha\Tasks;

use yii\base\Model;

class Numbers extends Model
{
    public $type = 'ImageToTextTask';
    public $body;
    public $numeric = 1;
    public $minLength;
    public $maxLength;
    public $CapMonsterModule;

    public function getHash()
    {
        return md5($this->body . $this->type . $this->numeric);
    }
}