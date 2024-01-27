<?php namespace common\components\services\models;

use yii\base\Model;

class ExtraField extends Model
{
    const TYPE_TEXT = 'text';
    const TYPE_ORDER_NUMBER = 'order_number';
    const TYPE_DROPDOWN = 'dropdown';

    public $name;
    public $sourceName;
    public $type;
    public $placeholder;
    public $title;
    public $shortTitle;
    public $mask;
    public $siteMask = null;
    public $field_regexp;
    public $delete_regexp = null;
    public $tip;

    public $validateRegexp = null;

    public $value;
    public $values = [];

    public $error;

    public function validateValue($value)
    {
        switch ($this->type) {
            case self::TYPE_TEXT;
                if (is_null($this->validateRegexp)) {
                    return true;
                }

                return preg_match('#' . $this->validateRegexp . '#siu', $value);
                break;

            case self::TYPE_DROPDOWN:
                return array_key_exists($value, $this->values);
                break;
        }
    }
}