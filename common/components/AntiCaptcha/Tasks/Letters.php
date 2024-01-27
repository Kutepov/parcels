<?php namespace common\components\AntiCaptcha\Tasks;

use yii\base\Model;

class Letters extends Model
{
    public $type = 'ImageToTextTask';
    public $body;
    public $numeric = 0;
    public $case = true;
    public $CapMonsterModule;

    public function getHash()
    {
        return md5($this->body . $this->type . $this->numeric);
    }
}