<?php namespace common\components\services;

use common\components\services\models\ExtraField;

interface ExtraFieldsInterface
{
    public function track($trackNumber, $extraFields = []);

    /**
     * @return ExtraField[]
     */
    public function extraFields();

    public function extraFieldsTip();

    public function extraFieldsTipApp();

    public static function validateTrackNumber($trackNumber);
}