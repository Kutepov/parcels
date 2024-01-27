<?php namespace common\components\AntiCaptcha\Tasks;

use yii\base\Model;

class ClickCaptcha extends Model
{
    public $type = 'coordinatescaptcha';
    public $image = '';
    public $method = 'method';
    public $textinstructions = 'center of puzzle';
}