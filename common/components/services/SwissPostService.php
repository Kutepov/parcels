<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class SwissPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 175;
    private $lang = 'de';
    private $messages = [];

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://service.post.ch/ekp-web/core/rest/translations/' . $this->lang . '/shipment-text-messages'), $trackNumber, [],
            function (ResponseInterface $response) use ($trackNumber) {
            $this->messages = json_decode($response->getBody()->getContents(), true);

            return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://service.post.ch/ekp-web/api/user'), $trackNumber, [
                RequestOptions::COOKIES => $jar = new CookieJar()
            ], function (ResponseInterface $response) use ($trackNumber, $jar) {
                $data = json_decode($response->getBody()->
                getContents());

                $userId = $data->userIdentifier;
                $csrfToken = $response->getHeader('X-CSRF-TOKEN')[0];

                return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://service.post.ch/ekp-web/api/history'), $trackNumber, [
                    RequestOptions::COOKIES => $jar,
                    RequestOptions::HEADERS => [
                        'x-csrf-token' => $csrfToken,
                    ],
                    RequestOptions::JSON => [
                        'searchQuery' => $trackNumber
                    ],
                    RequestOptions::QUERY => [
                        'userId' => $userId
                    ]
                ], function (ResponseInterface $response) use ($jar, $trackNumber, $userId) {
                    $data = json_decode($response->getBody()->getContents());

                    return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://service.post.ch/ekp-web/api/history/not-included/' . $data->hash), $trackNumber, [
                        RequestOptions::COOKIES => $jar,
                        RequestOptions::QUERY => [
                            'userId' => $userId
                        ]
                    ], function (ResponseInterface $response) use ($jar, $trackNumber) {
                        $data = json_decode($response->getBody()->getContents());

                        if (empty($data[0]->identity)) {
                            return false;
                        }

                        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://service.post.ch/ekp-web/api/shipment/id/' . $data[0]->identity . '/events'), $trackNumber, [
                            RequestOptions::COOKIES => $jar,
                        ]);
                    });
                });
            });
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $checkpoints = json_decode($response->getBody()->getContents(), true);

        $result = new Parcel();

        foreach ($checkpoints as $checkpoint) {
            list ($date, $time) = explode('T', $checkpoint['timestamp']);
            list ($time, $_) = explode('+', $time);

            $date = Carbon::parse($checkpoint['timestamp']);

            $result->statuses[] = new Status([
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => implode(', ', array_filter([$checkpoint['country'], $checkpoint['zip'], $checkpoint['city']])),
                'title' => $this->getDescriptionByCode($checkpoint['eventCode'])
            ]);
        }

        return $result;
    }

    /**
     * Look up the description for the given event code.
     *
     * @param $code
     *
     * @return string
     */
    public function getDescriptionByCode($code)
    {
        $haystack = $this->messages['shipment-text--'];

        $pattern = $this->getRegexPattern($code);

        $matches = array_filter(array_keys($haystack), function ($key) use ($pattern) {
            return 1 === preg_match($pattern, $key);
        });

        return !empty($matches) ? $haystack[array_values($matches)[0]] : '';
    }


    /**
     * Build a regex pattern for the code so it will match the exact code or wildcards.
     * E. g. if the code is 'LETTER.*.88.912', it should also match with 'LETTER.*.*.912'
     * or 'LETTER.*.88.912.*'
     *
     * @param $code
     *
     * @return string
     */
    protected function getRegexPattern($code)
    {
        $pattern = array_reduce(explode('.', $code), function ($regex, $part) {
            if (1 === preg_match('/[a-z]+/i', $part)) {
                return $regex .= $part;
            }

            if ($part === '*') {
                return $regex .= "\.(\*|[a-z]+|-|_)";
            }

            return $regex .= "\.(\*|{$part})";
        }, '');

        return sprintf("/%s(\.\*)?$/i", $pattern);
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}CH',
            'C[A-Z]{1}[0-9]{9}CH',
            'E[A-Z]{1}[0-9]{9}CH',
            'H[A-Z]{1}[0-9]{9}CH',
            'L[A-Z]{1}[0-9]{9}CH',
            'R[A-Z]{1}[0-9]{9}CH',
            'S[A-Z]{1}[0-9]{9}CH',
            'T[A-Z]{1}[0-9]{9}CH',
            'U[A-Z]{1}[0-9]{9}CH',
            'V[A-Z]{1}[0-9]{9}CH',
            'ASCT00[0-9]{7}',
            '[0-9]{13}B2C',
            '[0-9]{18}'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

}