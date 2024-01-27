<?php namespace common\components\services;

use common\components\guzzle\Client;
use common\components\services\events\TrackingCompletedEvent;
use common\components\services\exceptions\BadPreheatedCaptchaException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use yii\helpers\ArrayHelper;
use yii;

/**
 * Class BaseService
 * @package common\components\services
 */
abstract class BaseService extends yii\base\Component implements ServiceInterface
{
    const EXTRA_INFO = 'extraInfo';

    public $trackNumber = null;
    public $debug = false;

    public $timeZone = false;
    public $id;
    public $batchTrack = false;
    public $batchSize = 1;
    public $data;
    public $captcha = false;
    public $api = false;
    public $priority = 0;
    public $multiTracking = false;
    public $untrackable = false;
    public $phone = null;

    /** Принудительно делаем службу доставки "основной" для обновления вместе со службами стран назначения */
    public $mainAsyncCourier = false;
    /** Приоритет в обновлении асинхронно */
    public $asyncPriority = 0;

    protected $guzzle;

    public $batchTracking = false;

    const EVENT_TRACKING_COMPLETED = 'tracking_completed';
    const EVENT_INDEPENDENT_COURIER_FOUNDED = 'independent_courier_founded';

    public function __construct($data = null)
    {
        $this->guzzle = Yii::$container->get(Client::class);
        parent::__construct($data);
        $this->guzzle->debug = $this->debug;
    }

    /**
     * @param Request $request
     * @param string|array $trackNumbers
     * @param array $options
     * @param callable|null $callback
     * @param callable|null $onErrorCallback
     * @return PromiseInterface
     */
    protected function sendAsyncRequest(Request $request, $trackNumbers, $options = [], $callback = null, $onErrorCallback = null)
    {
        $extraInfo = [];

        if (!is_array($trackNumbers) && !is_null($trackNumbers)) {
            $this->trackNumber = $trackNumbers;
        }

        if (isset($options[self::EXTRA_INFO])) {
            $extraInfo = $options[self::EXTRA_INFO];
            unset($options[self::EXTRA_INFO]);
        }

        $promise = $this
            ->guzzle
            ->sendAsync($request, ArrayHelper::merge([
                'service_id' => $this->id,
                'track_number' => implode(', ', (array)$trackNumbers)
            ], $options));

        if ($callback !== false) {
            $promise = $promise
                ->then(function (ResponseInterface $response) use ($trackNumbers, $callback, $extraInfo) {
                    if (!is_null($callback)) {
                        return $callback($response);
                    }
                    elseif (!is_null($trackNumbers) && $this instanceof AsyncTrackingInterface) {
                        $trackNumbers = (array)$trackNumbers;

                        foreach ($trackNumbers as $trackNumber) {
                            $response->getBody()->seek(0);
                            try {
                                if ($this instanceof ComplicatedAsyncTrackingInterface) {
                                    $data = $this->parseResponse($response, $trackNumber, $extraInfo);
                                }
                                else {
                                    $data = $this->parseResponse($response, $trackNumber);
                                }
                            } catch (\Throwable $e) {
                                $data = false;
                            }

                            Yii::$app->trigger(self::EVENT_TRACKING_COMPLETED, new TrackingCompletedEvent([
                                'courierId' => $this->id,
                                'trackNumber' => $trackNumber,
                                'parcelInfo' => $data,
                                'success' => $data !== false
                            ]));
                        }

                        if (count($trackNumbers) == 1) {
                            return $data;
                        }
                        else {
                            return true;
                        }
                    }
                    else {
                        return $response;
                    }
                })
                ->otherwise(function ($error) use ($trackNumbers, $onErrorCallback, $options) {
                    if (!is_null($onErrorCallback)) {
                        return $onErrorCallback($error);
                    }

                    throw $error;

                    if ($this instanceof AsyncTrackingInterface) {
                        Yii::$app->trigger(self::EVENT_TRACKING_COMPLETED, new TrackingCompletedEvent([
                            'courierId' => $this->id,
                            'success' => false,
                            'trackNumber' => implode(', ', (array)$trackNumbers),
                            'response' => $error->getCode() . ' : ' . $error->getMessage(),
                            'needSyncRetry' => $error instanceof BadPreheatedCaptchaException,
                            'exception' => true
                        ]));
                    }

                    return false;
                });
        }

        return $promise;
    }

    /**
     * @param Request $request
     * @param $trackNumbers
     * @param array $options
     * @param callable|null $callback
     * @param callable|null $errorCallback
     * @param bool $proxyShift
     * @param bool $proxyRandom
     * @return PromiseInterface
     */
    protected function sendAsyncRequestWithProxy(Request $request, $trackNumbers, $options = [], $callback = null, $errorCallback = null, $proxyShift = true, $proxyRandom = false)
    {
//        $options[RequestOptions::PROXY] = $this->guzzle->getProxyForService($this->id, $proxyRandom, $proxyShift);

        return $this->sendAsyncRequest($request,
            $trackNumbers,
            $options,
            $callback,
            $errorCallback
        );
    }

    protected function sendSoapAsyncRequest($uri, $endPoint, $body, $trackNumber, $soapVersion = SOAP_1_2)
    {
        return $this->guzzle
            ->postAsync(
                $uri,
                [
                    'body' => $body,
                    'headers' => [
                        'Content-Type' => $soapVersion == SOAP_1_2 ? 'application/soap+xml; charset=utf-8' : 'text/xml',
                        'SOAPAction' => $endPoint
                    ],
                    'service_id' => $this->id,
                    'track_number' => $trackNumber,
                    'retry_on_status' => [502, 503, 506, 403, 400, 429]
                ]
            )
            ->then(function (ResponseInterface $response) use ($trackNumber) {

                try {
                    $data = $this->parseResponse($response, $trackNumber);
                } catch (\Throwable $e) {
                    $data = false;
                }

                Yii::$app->trigger(self::EVENT_TRACKING_COMPLETED, new TrackingCompletedEvent([
                    'courierId' => $this->id,
                    'trackNumber' => $trackNumber,
                    'parcelInfo' => $data,
                    'success' => $data !== false
                ]));

                return $data;
            })
            ->otherwise(function ($error) use ($trackNumber) {
                Yii::$app->trigger(self::EVENT_TRACKING_COMPLETED, new TrackingCompletedEvent([
                    'courierId' => $this->id,
                    'trackNumber' => $trackNumber,
                    'success' => false,
                    'response' => $error->getCode() . ' : ' . $error->getMessage(),
                    'exception' => true
                ]));

                return false;
            });
    }

    public function post($url, $data = [], $direct = false)
    {
        $data[RequestOptions::SYNCHRONOUS] = true;
        $result = $this->sendAsyncRequest(new Request('POST', $url), null, $data)->wait();

        return $direct ? $result : ($result ? $result->getBody()->getContents() : null);
    }

    /**
     * @param $url
     * @param array $data
     * @param bool $direct
     * @return string|Response
     */
    public function get($url, $data = [], $direct = false)
    {
        $data[RequestOptions::SYNCHRONOUS] = true;
        $result = $this->sendAsyncRequest(new Request('GET', $url), null, $data)->wait();

        return $direct ? $result : ($result ? $result->getBody()->getContents() : null);
    }

    public function postWithProxy($url, $data = [], $direct = false)
    {
        $data[RequestOptions::SYNCHRONOUS] = true;
        $result = $this->sendAsyncRequestWithProxy(new Request('POST', $url), null, $data)->wait();

        return $direct ? $result : ($result ? $result->getBody()->getContents() : null);
    }

    /**
     * @param $url
     * @param array $data
     * @param bool $direct
     * @return bool|Response
     */
    public function getWithProxy($url, $data = [], $direct = false)
    {
        $data[RequestOptions::SYNCHRONOUS] = true;
        $result = $this->sendAsyncRequestWithProxy(new Request('GET', $url), null, $data)->wait();

        return $direct ? $result : ($result ? $result->getBody()->getContents() : null);
    }

    public function proxyBanTime()
    {
        return 120;
    }
}