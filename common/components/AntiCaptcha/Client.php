<?php namespace common\components\AntiCaptcha;

use common\components\AntiCaptcha\Tasks\Numbers;
use common\components\AntiCaptcha\Tasks\Letters;
use common\components\AntiCaptcha\Tasks\ReCaptcha;
use yii\base\BaseObject;
use yii\base\Model;

class Client extends BaseObject
{
    /**
     * @var \GuzzleHttp\Client
     */
    private $guzzle;
    private $url = 'https://api.anti-captcha.com';
    private $key = 'fcaeae06deb46ed4f415c0f6428568b4';
    private $capMonsterUrl = '';
    private $capMonsterKey = '';


    public function __construct($config = [], \common\components\guzzle\Client $guzzle)
    {
        $this->guzzle = $guzzle;
        parent::__construct($config);
    }

    public function resolve(Model $task, $useWebService = false)
    {
        if ($task instanceof Numbers && !$task->body) {
            return false;
        }

        $useWebService = true;

        try {
            $request = $this->guzzle->post((!$useWebService ? $this->capMonsterUrl : $this->url) . '/createTask', [
                'json' => [
                    'clientKey' => !$useWebService ? $this->capMonsterKey : $this->key,
                    'task' => $task->getAttributes()
                ],
                'timeout' => 10,
                'connect_timeout' => 10
            ])->getBody()->getContents();

        } catch (\Exception $e) {
            return false;
        }

        $response = json_decode($request, true);

        if ($response['errorId']) {
            return null;
        }
        else {
            $taskId = $response['taskId'];
        }

        sleep(7);

        $iterations = 0;
        while ($iterations < 60) {
            try {
                $result = $this->guzzle->post((!$useWebService ? $this->capMonsterUrl : $this->url) . '/getTaskResult', [
                    'json' => [
                        'clientKey' => !$useWebService ? $this->capMonsterKey : $this->key,
                        'taskId' => $taskId
                    ],
                    'timeout' => 10,
                    'connect_timeout' => 10
                ])->getBody()->getContents();
                $result = json_decode($result, true);
                if ($result['status'] === 'ready' || $result['errorId']) {
                    break;
                }
            } catch (\Exception $e) {
                sleep(2);
                $iterations++;
                continue;
            }
            sleep(2);
            $iterations++;
        }

        if ($task instanceof ReCaptcha) {
            if (isset($result['solution']['gRecaptchaResponse'])) {
                return $result['solution']['gRecaptchaResponse'];
            }
            throw new \Exception(json_encode($result));
        }
        elseif ($task instanceof Numbers) {
            if (isset($result['solution']['text'])) {
                return $result['solution']['text'];
            }
            throw new \Exception(json_encode($result));
        }
        elseif ($task instanceof Letters) {
            if (isset($result['solution']['text'])) {
                return $result['solution']['text'];
            }
            throw new \Exception(json_encode($result));
        }

        return false;
    }
}