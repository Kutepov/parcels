<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class SwishipUkService extends SwishipService
{
    public $domain = 'co.uk';

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{10}' // QB0108230859
        ];
    }
}