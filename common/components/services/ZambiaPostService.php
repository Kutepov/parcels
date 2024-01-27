<?php

namespace common\components\services;

use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\RequestOptions;

class ZambiaPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface
{
    
    public $id = 149;
    private $url = 'http://165.56.6.54';

    public function track($trackNumber)
    {
        $request = $this->getWithProxy('http://165.56.6.54/webtracker/IPSWeb_item_events.asp', [
            RequestOptions::QUERY => [
                'itemid' => $trackNumber,
                'Submit' => 'Submit'
            ]
        ]);

        preg_match('/<tbody>(.*?)<\/tbody>/is', $request, $matches);

        if (!empty($matches[1])) {
            $statuses = [];

            preg_match_all('/<tr class=tabl.*?>(.*?)<\/tr>/is', $matches[1], $items);

            foreach ($items[1] as $key => $item) {

                preg_match_all('/<td.*?>(.*?)<\/td>/', $item, $data);

                $location = '';

                if (!empty(trim($data[1][1])) && !empty(trim($data[1][2]))) {
                    $location = $data[1][1] . ', ' . $data[1][2];
                } elseif (!empty(trim($data[1][1]))) {
                    $location = $data[1][1];
                } elseif (!empty(trim($data[1][2]))) {
                    $location = $data[1][2];
                }

                $statuses[] = new Status([
                    'title' => trim($data[1][3]),
                    'date' => $this->createDate($data[1][0]),
                    'location' => $location
                ]);
            }

            return new Parcel([
                'statuses' => $statuses
            ]);
        }

        return false;
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}ZM',
            'C[A-Z]{1}[0-9]{9}ZM',
            'E[A-Z]{1}[0-9]{9}ZM',
            'L[A-Z]{1}[0-9]{9}ZM',
            'R[A-Z]{1}[0-9]{9}ZM',
            'S[A-Z]{1}[0-9]{9}ZM',
            'V[A-Z]{1}[0-9]{9}ZM'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }
}