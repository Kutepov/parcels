<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use stdClass;

class PocztaPolska extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, InternationalValidateTrackNumberInterface
{
    public $id = 24;

    private $url = 'https://uss.poczta-polska.pl/uss/v1.0/tracking/checkmailex';


    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://uss.poczta-polska.pl/uss/v1.0/tracking/checkmailex'), $trackNumber, [
            RequestOptions::JSON => [
                'language' => 'PL',
                'number' => $trackNumber,
                'addPostOfficeInfo' => true
            ],
            RequestOptions::HEADERS => [
                'API_KEY' => 'BiGwVG2XHvXY+kPwJVPA8gnKchOFsyy39Thkyb1wAiWcKLQ1ICyLiCrxj1+vVGC+kQk3k0b74qkmt5/qVIzo7lTfXhfgJ72Iyzz05wH2XZI6AgXVDciX7G2jLCdoOEM6XegPsMJChiouWS2RZuf3eOXpK5RPl8Sy4pWj+b07MLg=.Mjg0Q0NFNzM0RTBERTIwOTNFOUYxNkYxMUY1NDZGMTA0NDMwQUIyRjg4REUxMjk5NDAyMkQ0N0VCNDgwNTc1NA==.b24415d1b30a456cb8ba187b34cb6a86',
            ]
        ]);
    }

    /**
     * @param Response|stdClass $response
     * @param string $trackNumber
     * @return Parcel|bool
     */
    public function parseResponse($response, $trackNumber)
    {
        $response = json_decode($response->getBody()->getContents(), true);

        if (!isset($response['mailInfo'])) {
            return false;
        }

        $result = new Parcel();

        $json = $response['mailInfo'];

        if (isset($json['weight'])) {
            $result->weight = $json['weight'] * 1000;
        }

        if (isset($json['recipientCountryCode'])) {
            $result->destinationCountryCode = $json['recipientCountryCode'];
            $result->destinationCountry = $json['recipientCountryName'];
        }

        foreach ($json['events'] as $event) {
            $date = Carbon::parse($event['time']);
            $result->statuses[] = new Status([
                'title' => $event['name'],
                'location' => isset($event['postOffice']['name']) ? $event['postOffice']['name'] : null,
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute')
            ]);
        }


        return $result;
    }

    public function track($trackNumber, $t = false)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}PL',
            '00\d{18}'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }
}