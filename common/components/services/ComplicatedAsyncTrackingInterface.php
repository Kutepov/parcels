<?php namespace common\components\services;

interface ComplicatedAsyncTrackingInterface extends AsyncTrackingInterface
{
    public function parseResponse($response, $trackNumber, $extraInfo = []);
}