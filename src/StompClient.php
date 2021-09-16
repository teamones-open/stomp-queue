<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Webman\Stomp;

use Workerman\Stomp\Client;

class StompClient extends Client
{

    public $queuePrefix = "";

    public $exchangeName = "";

    public $workerId = 0;

    /**
     * @var SmoothWeightedRobin|null
     */
    public $robin = null;

    public function __construct($address, $options = [], $workerId = 0)
    {
        parent::__construct($address, $options);

        $this->workerId = $workerId;
        $config = config('stomp', []);
        $preFix = !empty(config("belong_system")) ? config("belong_system") . "." : "";
        $this->queuePrefix = "/amq/queue/" . $preFix;
        $exchangeName = $preFix . $config['default']['amqp']['exchange'];
        $this->exchangeName = "/exchange/{$exchangeName}/{$preFix}";
    }

    /**
     * subscribe
     * @param $destination
     * @param callable $callback
     * @param array $headers
     * @return false|mixed|string
     */
    public function subscribe($destination, $callback, array $headers = [])
    {
        if ($this->checkDisconnecting()) {
            return false;
        }
        $raw_headers = $headers;
        $headers['id'] = isset($headers['id']) ? $headers['id'] : $this->createClientId();
        $headers['ack'] = isset($headers['ack']) ? $headers['ack'] : 'auto';
        $subscription = $headers['id'];
        $headers['destination'] = $this->queuePrefix . $destination;

        $package = [
            'cmd' => 'SUBSCRIBE',
            'headers' => $headers
        ];

        $this->sendPackage($package);

        $this->_subscriptions[$subscription] = [
            'ack' => $headers['ack'],
            'callback' => $callback,
            'headers' => $raw_headers,
            'destination' => $this->queuePrefix . $destination,
        ];
        return $subscription;
    }

    /**
     * @param $destination
     * @param $callback
     * @param array $headers
     *
     * @return string
     */
    public function subscribeWithAck($destination, $callback, array $headers = [])
    {
        if (!isset($headers['ack']) || $headers['ack'] === 'auto') {
            $headers['ack'] = 'client';
        }
        return $this->subscribe($this->queuePrefix . $destination, $callback, $headers);
    }

    /**
     * @param $destination
     * @param $body
     * @param int $delay 单位是秒
     * @param array $headers
     */
    public function send($destination, $body, $delay = 0, array $headers = [])
    {
        if ($delay > 0) {
            $headers['x-delay'] = $delay * 1000;
        }

        $headers['destination'] = $this->exchangeName . $destination;

        $headers['content-length'] = strlen($body);
        if (!isset($headers['content-type'])) {
            $headers['content-type'] = 'text/plain';
        }

        $package = [
            'cmd' => 'SEND',
            'headers' => $headers,
            'body' => $body
        ];

        //var_dump($package);

        $this->sendPackage($package);
    }
}