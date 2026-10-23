<?php

declare(strict_types=1);

namespace Webman\Stomp;

use Webman\Stomp\AmqpLib\Enforcer;
use Workerman\Stomp\Client;

/**
 * STOMP client that maps queue names onto the AMQP topology declared by Enforcer.
 */
class StompClient extends Client
{
    /**
     * Connection name from config/stomp.php.
     */
    public string $_configName = '';

    public function __construct($address, $options = [])
    {
        parent::__construct($address, $options);
    }

    /**
     * @param string $namespace
     * @param string $queueName
     * @return string
     */
    protected function getQueuePath($namespace, $queueName)
    {
        // /amq/queue/[queue_name]
        return "/amq/queue/{$namespace}.{$queueName}";
    }

    /**
     * @param string $name
     * @param string $queueName
     * @return string
     */
    protected function getExchangePath($name, $queueName)
    {
        // /exchange/[exchangename]/[routing_key]
        $exchangename = Enforcer::$_namespace[$name] . '.' . Enforcer::$_amqpConfig[$name]['exchange_name'];
        $routingKey = Enforcer::$_namespace[$name] . '.' . $queueName;

        return "/exchange/{$exchangename}/{$routingKey}";
    }

    /**
     * @param string $queueName
     * @param callable $callback
     * @param array $headers
     * @return false|string
     */
    public function subscribe($queueName, $callback, array $headers = [])
    {
        if ($this->checkDisconnecting()) {
            return false;
        }

        // On reconnect workerman re-subscribes with the stored headers; short_name
        // keeps the bare queue name so the destination is not prefixed twice.
        $queueName = (string)($headers['short_name'] ?? $queueName);
        unset($headers['short_name']);
        if ($queueName === '') {
            throw new \RuntimeException('Stomp queue name cannot be empty');
        }

        $headers['id'] = $headers['id'] ?? $this->createClientId();
        $headers['ack'] = $headers['ack'] ?? 'auto';
        $subscription = (string)$headers['id'];

        $destination = $this->getQueuePath($this->connectionNamespace(), $queueName);
        $headers['destination'] = $destination;

        $package = [
            'cmd' => 'SUBSCRIBE',
            'headers' => $headers
        ];

        $this->sendPackage($package);

        $this->_subscriptions[$subscription] = [
            'ack' => $headers['ack'],
            'callback' => $callback,
            'headers' => ['short_name' => $queueName] + $headers,
            'destination' => $destination,
        ];
        return $subscription;
    }

    /**
     * @param string $queueName
     * @param callable $callback
     * @param array $headers
     * @return false|string
     */
    public function subscribeWithAck($queueName, $callback, array $headers = [])
    {
        if (!isset($headers['ack']) || $headers['ack'] === 'auto') {
            $headers['ack'] = 'client';
        }

        // subscribe() owns the destination building; passing a built destination
        // here used to prefix the queue name twice.
        return $this->subscribe($queueName, $callback, $headers);
    }

    /**
     * @param string $queueName
     * @return bool
     */
    public function hasSubscriptionForQueue($queueName): bool
    {
        $queueName = (string)$queueName;
        if ($queueName === '' || $this->_configName === '' || !isset(Enforcer::$_namespace[$this->_configName])) {
            return false;
        }
        $destination = $this->getQueuePath(Enforcer::$_namespace[$this->_configName], $queueName);
        foreach ($this->_subscriptions as $subscription) {
            if (($subscription['destination'] ?? null) === $destination) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $queueName
     * @param string $body message body, must already be serialized
     * @param int $delay delay in seconds, requires amqp.exchange_delay
     * @param array $headers
     * @return void
     */
    public function send($queueName, $body, $delay = 0, array $headers = [])
    {
        if (!is_string($queueName) || $queueName === '') {
            throw new \RuntimeException('Stomp queue name cannot be empty');
        }
        if (!is_string($body)) {
            if (is_scalar($body) || $body instanceof \Stringable) {
                $body = (string)$body;
            } else {
                throw new \InvalidArgumentException('Stomp message body must be a string');
            }
        }

        $delay = (int)$delay;
        if ($delay > 0) {
            if (empty($this->amqpConfig()['exchange_delay'])) {
                throw new \RuntimeException(
                    "Delayed messages require amqp.exchange_delay=true on connection {$this->_configName}"
                );
            }
            $headers['x-delay'] = $delay * 1000;
        }

        $headers['destination'] = $this->getExchangePath($this->_configName, $queueName);
        $headers['content-length'] = strlen($body);
        if (!isset($headers['content-type'])) {
            $headers['content-type'] = 'text/plain';
        }

        $package = [
            'cmd' => 'SEND',
            'headers' => $headers,
            'body' => $body
        ];

        $this->sendPackage($package);
    }

    /**
     * @return string
     */
    protected function connectionNamespace(): string
    {
        if ($this->_configName === '' || !isset(Enforcer::$_namespace[$this->_configName])) {
            throw new \RuntimeException('Stomp connection is not initialized');
        }
        return Enforcer::$_namespace[$this->_configName];
    }

    /**
     * @return array<string, mixed>
     */
    protected function amqpConfig(): array
    {
        if (!isset(Enforcer::$_amqpConfig[$this->_configName])) {
            throw new \RuntimeException("Stomp connection {$this->_configName} is not initialized");
        }
        return Enforcer::$_amqpConfig[$this->_configName];
    }
}
