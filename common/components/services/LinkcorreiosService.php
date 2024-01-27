<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;

class LinkcorreiosService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 321;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}BR' // ON292890354BR
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://www.linkcorreios.com.br/?id=' . $trackNumber), $trackNumber);
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();

        if (stripos($data, 'O rastreamento não está disponível no momento') !== false) {
            return false;
        }

        $dom = (new Dom())->loadStr($data);
        $result = new Parcel();

        foreach ($dom->find('.linha_status') as $checkpoint) {
            $dateString = $this->clearString(
                $checkpoint->find('li', 1)->text,
                ['Data : ', '| Hora: ', '-' => '/']
            );

            $location = $this->clearString(
                $checkpoint->find('li', 2)->text,
                ['Local: ', 'Origem: ']
            );

            $dateTime = Carbon::parse($dateString);
            $result->statuses[] = new Status([
                'title' => $checkpoint->find('li', 0)->find('b')->text,
                'date' => $dateTime->timestamp,
                'location' => $location,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    private function clearString(string $string, array $clear): string
    {
        foreach ($clear as $index => $item) {
            $string = str_replace($item, !is_numeric($index) ? $index : '', $string);
        }
        return $string;
    }

    public function restrictCountries(): array
    {
        return ['br'];
    }
}