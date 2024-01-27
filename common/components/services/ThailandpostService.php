<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class ThailandpostService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{
    private const statuses = [
        101 => 'เตรียมการฝากส่ง',
        102 => 'รับฝากผ่านตัวแทน',
        103 => 'รับฝาก',
        201 => 'อยู่ระหว่างการขนส่ง',
        202 => 'ดำเนินพิธีการศุลกากร',
        203 => 'ส่งคืนต้นทาง',
        204 => 'ถึงที่ทำการแลกเปลี่ยนระหว่างประเทศขาออก',
        205 => 'ถึงที่ทำการแลกเปลี่ยนระหว่างประเทศขาเข้า',
        206 => 'ถึงที่ทำการไปรษณีย์',
        208 => 'ส่งออกจากที่ทำการแลกเปลี่ยนระหว่างประเทศขาออก',
        209 => 'ยกเลิกการส่งออก',
        210 => 'ยกเลิกการนำเข้า',
        301 => 'อยู่ระหว่างการนำจ่าย',
        302 => 'นำจ่าย ณ จุดรับสิ่งของ',
        401 => 'นำจ่ายไม่สำเร็จ',
        501 => 'นำจ่ายสำเร็จ',
        901  => 'โอนเงินให้ผู้ขายเรียบร้อยแล้ว',
    ];

    private $tokenApi = 'KZERY%QHMmYnEaH.P@C+DSVBYKXhOSTdC9GBNBTRWKNHDrE5KsJ-EMHHRXNwL$BrSHSaNAAkN;EiYDS-VUFEWqHREvJWB.YQNTMA';

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->request($trackNumber);
    }

    public function track($trackNumber)
    {
        return $this->request($trackNumber)->wait();
    }

    public function request($trackNumber)
    {

        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://trackapi.thailandpost.co.th/post/api/v1/authenticate/token'), $trackNumber, [
            RequestOptions::HEADERS => ['Authorization' => 'Token ' . $this->tokenApi],
            RequestOptions::JSON => ["clientOrderId" => $trackNumber]
        ], function (ResponseInterface $response) use ($trackNumber) {

            $data = json_decode($response->getBody()->getContents(), true);
            $token = $data['token'];

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://trackapi.thailandpost.co.th/post/api/v1/track'), $trackNumber, [
                    RequestOptions::HEADERS => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Token ' . $token
                    ],
                    RequestOptions::BODY => json_encode([
                        'status' => 'all',
                        'language' => 'TH',
                        'barcode' => [$trackNumber]
                    ])
                ]
            );
        });
    }


    public function parseResponse($response, $trackNumber)
    {
        $data = json_decode($response->getBody()->getContents(), true);
        if (count($data['response']['items'][$trackNumber])) {
            foreach ($data['response']['items'][$trackNumber] as $checkpoint) {
                $dateString = str_replace('/', '-', $checkpoint['status_date']);
                $date = Carbon::parse($dateString);

                $statuses[] = new Status([
                    'title' => self::statuses[$checkpoint['status']],
                    'location' => $checkpoint['location'],
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute')
                ]);
            }
        }

        return isset($statuses) ? new Parcel(['statuses' => $statuses]) : false;
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' //EG360891648TH
        ];
    }
}