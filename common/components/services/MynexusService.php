<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\ExtraField;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class MynexusService extends BaseService implements ValidateTrackNumberInterface, ExtraFieldsInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 467;

    public static function validateTrackNumber($trackNumber)
    {
        return true;
    }

    public function parseResponse($response, $trackNumber)
    {
        $data = $response->getBody()->getContents();
        $dom = new Crawler($data);

        $result = new Parcel();

        $date = null;
        $dom->filterXPath('//ol[@class="list-group vertical-steps"]//li[contains(@class, " completed")]|//ol[@class="list-group vertical-steps"]//li[contains(@class, " active")]')->each(function (Crawler $checkpoint) use (&$result, &$date) {
            if ($checkpoint->filterXPath('//span[@class="hub-icon "]')->count()) {
                return;
            }

            $pregDate = null;
            if (!preg_match('/[0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4} [0-9]{2}:[0-9]{2}/', $checkpoint->filterXPath('//span')->text(), $pregDate)) {
                if (!$date) {
                    return;
                }
            } else {
                $date = $pregDate[0];
            }

            $status = preg_replace('/[0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4} [0-9]{2}:[0-9]{2}/', '', $checkpoint->filterXPath('//span')->text());
            $status = str_replace(': ', '', $status);

            $date = Carbon::parse(str_replace('/', '-', $date));
            $result->statuses[] = new Status([
                'title' => trim($status),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        });

        if ($dom->filterXPath('//h6[contains(text(), "Your order is coming from:")]/following-sibling::span')->count()) {
            $result->sender = $dom->filterXPath('//h6[contains(text(), "Your order is coming from:")]/following-sibling::span')->text();
        }

        if ($dom->filterXPath('//h6[contains(text(), "To this location:")]/following-sibling::h6')->count()) {
            $result->recipient = $dom->filterXPath('//h6[contains(text(), "To this location:")]/following-sibling::h6')->text();
        }

        if ($dom->filterXPath('//h6[contains(text(), "To this location:")]/following-sibling::textarea')->count()) {
            $result->destinationAddress = $dom->filterXPath('//h6[contains(text(), "To this location:")]/following-sibling::textarea')->text();
        }

        return (!empty($result->statuses)) ? $result : false;
    }

    private function request($trackNumber, $extraFields = [])
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://mynexus.pallex.com/NexusTracking/' . $trackNumber . '/' . $extraFields['postCode']), $trackNumber);
    }

    public function trackAsync($trackNumber, $extraFields = []): PromiseInterface
    {
        return $this->request($trackNumber);
    }

    public function track($trackNumber, $extraFields = [])
    {
        return $this->request($trackNumber, $extraFields)->wait();
    }


    public function trackNumberRules(): array
    {
        return ['[0-9]{16}']; //1044000106606774
    }

    public function extraFields()
    {
        return [
            new ExtraField([
                'type' => ExtraField::TYPE_TEXT,
                'name' => 'postCode',
                'placeholder' => 'Post code',
                'error' => \t('Заполните поле'),
                'values' => [2020 => 2020, 2021 => 2021]
            ])
        ];
    }

    public function extraFieldsTestValues(): array
    {
        return [
            'postCode' => 'CF14 9FJ',
        ];
    }


    public function extraFieldsTipApp()
    {
        return \t('Чтобы отследить посылку вам необходимо ввести дополнительную информацию.');
    }

    public function extraFieldsTip()
    {
        return \t('Чтобы отследить посылку вам необходимо ввести дополнительную информацию.');
    }

    public function restrictCountries()
    {
        return [
            'uk',
            'pl',
            'ro',
        ];
    }
}