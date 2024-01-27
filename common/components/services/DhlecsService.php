<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;

class DhlecsService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface
{

    public function track($trackNumber)
    {
        try {
            return $this->trackAsync($trackNumber)->wait();
        }
        catch (\Exception $exception) {}
    }

    public function trackNumberRules(): array
    {
        return [
            '[0-9]{16}' // 4051404174475192
        ];
    }

    private function getHost()
    {
        return 'https://webtrack.dhlecs.com/';
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://webtrack.dhlecs.com/?trackingnumber='.$trackNumber), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {

            $data = $response->getBody()->getContents();
            preg_match("/TSPD.*.\">/", $data, $jsUrl);
            $jsUrl = substr($jsUrl[0], 0, -2);

            return $this->sendAsyncRequestWithProxy(new Request('GET', $this->getHost().$jsUrl), $trackNumber, [
                RequestOptions::COOKIES => $jar,
            ], function (ResponseInterface $response) use ($trackNumber, $jar) {

                return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://webtrack.dhlecs.com/?trackingnumber='.$trackNumber), $trackNumber, [
                    RequestOptions::COOKIES => $jar,
                ]);

            });
        });
    }

    public function parseResponse($response, $trackNumber)
    {
    }
}