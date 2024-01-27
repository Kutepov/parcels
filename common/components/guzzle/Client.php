<?php namespace common\components\guzzle;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use yii\helpers\Json;

class Client extends \GuzzleHttp\Client
{
    public $debug = false;

    public function __construct(array $config = [])
    {
        $stack = HandlerStack::create();
        $retryAttemptsCount = \Yii::$app instanceof \yii\console\Application ? 16 : 6;

        $stack->before(RequestOptions::ALLOW_REDIRECTS, GuzzleRetryMiddleware::factory([
            'retry_on_timeout' => true,
            'default_retry_multiplier' => 0,
            'retry_on_status' => [500, 502, 503, 506, 403, 400, 429],
            'max_retry_attempts' => $retryAttemptsCount,
            'on_retry_callback' => function ($attemptNumber, $delay, RequestInterface &$request, &$options, ResponseInterface $response = null, $reason) use ($retryAttemptsCount) {
                $serviceId = $options['service_id'] ?? 0;

                if ($this->debug) {
                    echo 'attempt #' . $attemptNumber . PHP_EOL;
                    echo date('[H:i:s]') . ' [' . $serviceId . '] ' . ($response ? $response->getStatusCode() . ':' : '') . ' ' . ($reason ? get_class($reason) . ': ' . $reason->getMessage() : '') . PHP_EOL;
                    echo date('[H:i:s]') . ' [' . $serviceId . '] [' . $options['track_number'] . '] ' . $request->getMethod() . ' ' . $request->getUri() . ' ' . Json::encode($request->getBody()->getContents()) . PHP_EOL;
                    if ($options['proxy']) {
                        echo date('[H:i:s]') . ' [' . $serviceId . '] retry with proxy ' . $options['proxy'] . '...' . PHP_EOL;
                    }
                }
            }]), 'retry');

        $config = [
            'handler' => $stack,
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => 6,
                'track_redirects' => true
            ],
            RequestOptions::HEADERS => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.88 Safari/537.36',
                'Accept-Encoding' => 'gzip, deflate',
            ],
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 10,
            RequestOptions::VERIFY => false
        ];

        parent::__construct($config);
    }
}