<?php namespace common\components\services;

interface BatchTrackInterface
{
    public function batchTrack($trackNumbers = []);

    public function batchTrackMaxCount();
}