<?php namespace console\controllers;

use common\components\services\XpressbeesService;
use yii\console\Controller;

class SandboxController extends Controller
{
    public function actionIndex()
    {
        dd((new XpressbeesService())->track('14227820082477'));
    }
}