<?php namespace common\components\services\events;

use common\components\services\models\Parcel;
use yii\base\Event;

/**
 * Class TrackingCompletedEvent
 * @package common\components\services\events
 *
 * @property Parcel $parcelInfo
 */
class TrackingCompletedEvent extends Event
{
    public $success = true;
    public $exception = null;
    public $courierId;
    public $trackNumber;
    public $parcelInfo;
    public $response;
    public $needSyncRetry = false;
}