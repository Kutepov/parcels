<?php namespace common\components\services;

use common\components\services\exceptions\BadPreheatedCaptchaException;
use common\components\services\models\Parcel;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use stdClass;

interface AsyncTrackingInterface
{
    /**
     * @param $trackNumber
     * @return PromiseInterface
     * @throws BadPreheatedCaptchaException
     */
    public function trackAsync($trackNumber): PromiseInterface;

    /**
     * @param Response|stdClass|ResponseInterface $response
     * @param string $trackNumber
     * @return Parcel|bool
     */
    public function parseResponse($response, $trackNumber);
}