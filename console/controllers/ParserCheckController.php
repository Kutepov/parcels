<?php namespace console\controllers;

use common\components\services\BaseService;
use common\components\services\BatchTrackInterface;
use common\components\services\events\TrackingCompletedEvent;
use common\components\services\ExtraFieldsInterface;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use Symfony\Component\VarDumper\VarDumper;
use yii\console\Controller;
use yii\helpers\Console;

class ParserCheckController extends Controller
{
    public $debug = false;

    public function options($actionID)
    {
        return ['debug'];
    }

    public function actionIndex(string $className, string $trackNumber)
    {
        $className = '\\common\\components\\services\\' . $className;

        if (!class_exists($className)) {
            $this->stdFatalErr('Parser ' . $className . ' not found.');
        }

        /** @var BaseService $parser */
        $parser = new $className(['debug' => $this->debug]);

        if ($parser instanceof ExtraFieldsInterface) {
            if (method_exists($parser, 'extraFieldsTestValues')) {
                $extraFields = $parser->extraFieldsTestValues();
                $result = $parser->track($trackNumber, $extraFields);
                VarDumper::dump($result);
                $this->validateResult($result);
                $this->checkWithWrongTrackNumber($parser);
            }
            else {
                $this->stdFatalErr('Method `extraFieldsTestValues()` not implemented');
            }
        }
        else {
            $trackNumbers = explode(',', $trackNumber);
            $result = $parser->track($trackNumbers[0]);
            $this->stdout('Single tracking result for track number ' . $trackNumbers[0] . ':', Console::FG_PURPLE);
            VarDumper::dump($result);
            $this->validateResult($result);
            $this->checkWithWrongTrackNumber($parser);

            if (count($trackNumbers) >= 2) {
                if ($parser instanceof BatchTrackInterface) {
                    /** @var Parcel[] $batchResults */
                    $batchResults = [];
                    $trackNumbers = array_slice($trackNumbers, 0, 2);
                    \Yii::$app->on(BaseService::EVENT_TRACKING_COMPLETED, function (TrackingCompletedEvent $event) use (&$batchResults) {
                        $this->stdout('Batch tracking result for track number ' . $event->trackNumber . ':', Console::FG_PURPLE);
                        VarDumper::dump($event->parcelInfo);
                        $this->validateResult($event->parcelInfo);
                        if ($event->success) {
                            $batchResults[] = $event->parcelInfo;
                        }
                    });
                    $parser->batchTrack($trackNumbers)->wait();

                    if ($batchResults[0]->statusesHash === $batchResults[1]->statusesHash) {
                        $this->stdFatalErr('Batch tracking results for both track numbers are identical.');
                    }
                }
                else {
                    $this->stdFatalErr('BatchTrackInterface not implemented');
                }
            }
        }

        $this->stdout('All is ok.', Console::FG_GREEN);
    }

    private function validateResult($result): void
    {
        if (!($result instanceof Parcel)) {
            $this->stdFatalErr('Result is not instance of ' . Parcel::class);
        }

        if (!is_array($result->statuses)) {
            $this->stdFatalErr('"statuses" property is not a array');
        }

        foreach ($result->statuses as $k => $checkpoint) {
            if (!($checkpoint instanceof Status)) {
                $this->stdFatalErr('Status #' . $k . ' is not instance of ' . Status::class);
            }

            if (!trim($checkpoint->title)) {
                $this->stdFatalErr('"title" property in status #' . $k . ' is empty.');
            }

            if (!$checkpoint->date || !is_numeric($checkpoint->date) || strlen($checkpoint->date) !== 10) {
                $this->stdFatalErr('"date" property in status #' . $k . ' is invalid.');
            }

            if ($checkpoint->dateVal && !preg_match('#^\d{4}-\d{2}-\d{2}$#', $checkpoint->dateVal)) {
                $this->stdFatalErr('"dateVal" property in status #' . $k . ' is invalid.');
            }

            if ($checkpoint->timeVal && !preg_match('#^\d{2}:\d{2}$#', $checkpoint->timeVal)) {
                $this->stdFatalErr('"timeVal" property in status #' . $k . ' is invalid.');
            }

            if ($checkpoint->timezoneVal && (!is_numeric($checkpoint->timezoneVal) || $checkpoint->timezoneVal < -12 || $checkpoint->timezoneVal > 14)) {
                $this->stdFatalErr('"timezoneVal" property in status #' . $k . ' is invalid.');
            }
        }

        if ($result->destinationCountryCode && !preg_match('#^[a-z]{2}$#i', $result->destinationCountryCode)) {
            $this->stdFatalErr('"destinationCountryCode" property has a wrong value.');
        }

        if ($result->departureCountryCode && !preg_match('#^[a-z]{2}$#i', $result->departureCountryCode)) {
            $this->stdFatalErr('"departureCountryCode" property has a wrong value.');
        }

        if ($result->estimatedDeliveryTime && (!is_numeric($result->estimatedDeliveryTime) || strlen($result->estimatedDeliveryTime) !== 10)) {
            $this->stdFatalErr('"estimatedDeliveryTime" property has a wrong value.');
        }

        if ($result->weight && !is_numeric($result->weight)) {
            $this->stdFatalErr('"weight" property has a wrong value.');
        }
    }

    private function checkWithWrongTrackNumber(BaseService $parser)
    {
        $fakeTrackNumber = \Yii::$app->security->generateRandomString(16);
        if ($parser instanceof ExtraFieldsInterface) {
            if (method_exists($parser, 'extraFieldsTestValues')) {
                $extraFields = $parser->extraFieldsTestValues();
                $result = $parser->track($fakeTrackNumber, $extraFields);
            }
            else {
                $this->stdFatalErr('Method `extraFieldsTestValues()` not implemented');
            }
        }
        else {
            $result = $parser->track($fakeTrackNumber);
        }

        if ($result instanceof Parcel) {
            $this->stdFatalErr('Parsing result with fake track number (' . $fakeTrackNumber . ') returns ' . Parcel::class . ' instead of (bool)false.');
        }
    }

    public function stdout($string, $color = null)
    {
        return parent::stdout(date('[H:i:s]') . ' ' . $string . PHP_EOL, $color);
    }

    public function stdFatalErr($string)
    {
        $this->stderr('[FATAL] ' . $string);
        exit;
    }

    public function stderr($string)
    {
        return parent::stderr(date('[H:i:s]') . ' ' . $string . PHP_EOL, Console::FG_RED);
    }
}