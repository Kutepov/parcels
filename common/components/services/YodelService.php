<?php namespace common\components\services;

use common\components\services\models\Parcel;
use common\components\services\models\Status;
use common\models\Country;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use yii;

class YodelService extends BaseService implements ServiceInterface, BatchTrackInterface, ValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 35;
    private $url = 'https://www.yodel.co.uk/tracking/%s';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', sprintf($this->url, $trackNumber)), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = rmnl($response->getBody()->getContents());

        $regexp = '#' . rmnl('<div class="tracking-event row (?:.*?)">
                                        <div class="tracking-event-date col-4 col-md-3">
                                            <div class="tracking-event-date-inner">
                                                <div class="tracking-event-date-year">(.*?)</div>
                                                <div class="tracking-event-date-time">(.*?)</div>
                                            </div>
                                        </div>
                                        <div class="col-8 col-md-9">
                                            <div class="tracking-event-description">
                                                (.*?)
                                            </div>
                                        </div>
                                    </div>') . '#si';

        if (preg_match_all($regexp, $data, $checkpoints, PREG_SET_ORDER)) {
            foreach ($checkpoints as $checkpoint) {
                $date = explode('/', $checkpoint[1]);
                $date[2] += 2000;
                $date = implode('.', $date);

                $statuses[] = new Status([
                    'title' => fixCapitalize(preg_replace('#^Your #i', '', $checkpoint[3])),
                    'date' => Yii::$app->formatter->asTimestamp($date)
                ]);
            }
        }

        return new Parcel([
            'statuses' => $statuses
        ]);
    }

    private static function findCountry($name)
    {
        $name = str_ireplace('Russian Fed.', 'Russian Federation', $name);

        if ($country = Country::findByTranslate($name)) {
            return $country;
        }

        return null;
    }

    public function batchTrack($trackNumbers = [])
    {
        // TODO: Implement batchTrack() method.
    }

    public function batchTrackMaxCount()
    {
        return 1;
    }

    public function trackNumberRules(): array
    {
        return [
            'JD\d{16}',
            'JJD\d{16}',
            '246440\d{6}'
        ];
    }
}