<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use stdClass;

class AustrianPostService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, InternationalValidateTrackNumberInterface
{
    public $id = 169;
    private $url = 'https://www.post.at';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }


    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}AT',
            'C[A-Z]{1}[0-9]{9}AT',
            'E[A-Z]{1}[0-9]{9}AT',
            'L[A-Z]{1}[0-9]{9}AT',
            'R[A-Z]{1}[0-9]{9}AT',
            'S[A-Z]{1}[0-9]{9}AT',
            'U[A-Z]{1}[0-9]{9}AT',
            'V[A-Z]{1}[0-9]{9}AT',
            'FT[A-Z]{1}[0-9]{9}AT',
            'XX[A-Z]{1}[0-9]{9}AT',
            '[0-9]{14}',
            '[0-9]{16}',
            '10[0-9]{20}',
            '16[0-9]{20}',
            '010052[0-9]{20}',
            '158002[0-9]{20}',
            '158280[0-9]{20}',
            '158490[0-9]{20}'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://api.post.at/sendungen/sv/graphqlPublic'), $trackNumber, [
            RequestOptions::HEADERS => [
                'Referer' => 'https://www.post.at/sv/sendungsdetails?snr=' . $trackNumber
            ],
            RequestOptions::JSON => json_decode('{"query":"query {\n        einzelsendung(sendungsnummer: \"' . $trackNumber . '\") {\n          sendungsnummer\n          branchkey\n          estimatedDelivery {\n            startDate\n            endDate\n            startTime\n            endTime\n          }\n          dimensions {\n            height\n            width\n            length\n          }\n          status\n          weight\n          sendungsEvents {\n            timestamp\n            status\n            reasontypecode\n            text\n            textEn\n            eventpostalcode\n            eventcountry\n          }\n          customsInformation {\n            customsDocumentAvailable,\n            userDocumentNeeded\n          }\n        }\n      }"}')
        ]);
    }

    /**
     * @param Response|stdClass $response
     * @param string $trackNumber
     * @return Parcel|bool
     */
    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents());

        if ($data->data->einzelsendung) {
            $data = $data->data->einzelsendung;

            $result = new Parcel();

            foreach ($data->sendungsEvents as $event) {

                $date = Carbon::parse($event->timestamp);
                $result->statuses[] = new Status([
                    'title' => $event->textEn ?: $event->text,
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute'),
                    'location' => $event->reasontypecode
                ]);
            }

            return (!empty($result->statuses)) ? $result : false;
        }

        return false;
    }
}