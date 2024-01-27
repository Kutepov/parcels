<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

class PonyexpressService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, BatchTrackInterface, CountryRestrictionInterface
{
    public $id = 428;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function batchTrackMaxCount()
    {
        return 10;
    }

    public function batchTrack($trackNumbers = [])
    {
        return $this->trackAsync($trackNumbers);
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        $params['captcha_code'] = '';
        if (is_array($trackNumber)) {
            foreach ($trackNumber as $index => $number) {
                $params['trace_ids[' . $index . ']'] = $number;
            }
        }
        else {
            $params['trace_ids[]'] = $trackNumber;
        }
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://www.ponyexpress.ru/local/ajax/track.php'), $trackNumber, [
            RequestOptions::FORM_PARAMS => $params
        ]);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);

        $result = new Parcel();
        foreach ($data['result'][$trackNumber] as $checkpoint) {
            if ($checkpoint['EventTypeEng'] === 'No database, try again later.') {
                return false;
            }
            $date = Carbon::parse($checkpoint['EventDT'] . ' ' . $checkpoint['EventTM']);
            $result->statuses[] = new Status([
                'title' => $checkpoint['EventType'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $checkpoint['From'],
            ]);

        }
        return $result;
    }

    public function trackNumberRules(): array
    {
        return ['[0-9]{2}-[0-9]{4}-[0-9]{4}']; //26-9201-9209
    }

    public function restrictCountries()
    {
        return ['ru', 'kz', 'by', 'ua'];
    }
}