<?php

namespace common\components\services;

use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\RequestOptions;

class QuickFishService extends BaseService implements ValidateTrackNumberInterface
{
    
    public $id = 124;
    private $url = 'http://www.iquickfish.com';

    public function track($trackNumber)
    {
        $request = $this->postWithProxy('http://www.iquickfish.com/p/track/Tracking.aspx', [
            'timeout' => 30,
            'connect_timeout' => 30,
            RequestOptions::FORM_PARAMS => [
                '__VIEWSTATE' => '+H5Rlbu7cUufQGzp5ml92Wtku0mErxYQGvssOdHHWPRUOfM84HZMa+gvBjm8NB+/V/WxwHJTaFkGb6wGHVKxd3wx1Nw=',
                '__VIEWSTATEGENERATOR' => '2F491C14',
                'trackNoList' => $trackNumber,
                'btnSearch' => '查询'
            ]
        ]);

        preg_match('/margin-top: 10px;">(.*?)<\/div>/is', $request, $matches);

        if (!empty($matches[1])) {
            $statuses = [];

            preg_match_all('/([0-9].*?)[&nbsp;]{2,}(.*?)<br>/ms', $matches[1], $items);

            foreach ($items[1] as $key => $item) {

                $statuses[] = new Status([
                    'title' => trim($items[2][$key]),
                    'date' => $this->createDate($item)
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
            'KFY[A-Z]{2}[0-9]{10}YQ'
        ];
    }
}