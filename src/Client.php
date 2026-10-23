<?php

declare(strict_types=1);

namespace Webman\Stomp;

use Webman\Stomp\AmqpLib\Enforcer;

/**
 * Static entry point of the STOMP queue.
 *
 * Strings methods
 * @method static mixed subscribe(string $queueName, callable $callback, array $headers = [])
 * @method static void send(string $queueName, $body, int $delay = 0, array $headers = [])
 */
class Client
{
    /**
     * @var array<string, Client>|null
     */
    protected static ?array $_connections = null;

    /**
     * Calls buffered until the STOMP connection is established.
     *
     * @var array<int, array{0: string, 1: array}>
     */
    protected array $_queue = [];

    protected StompClient $_client;

    /**
     * Cap the buffer so a broker outage cannot grow memory without bound.
     */
    protected static int $maxQueueLength = 10000;

    /**
     * @var string[]
     */
    protected static array $queueableMethods = [
        'subscribe', 'subscribeWithAck', 'unsubscribe', 'send', 'ack', 'nack', 'disconnect',
    ];

    /**
     * @param string $name
     * @param array $config
     */
    public function __construct($name, $config = [])
    {
        $this->_client = new StompClient($config['host'], $config);
        $this->_client->_configName = (string)$name;
        $this->_client->onConnect = function ($client) {
            $this->flushQueue($client);
        };

        // workerman re-subscribes known destinations on reconnect and reports it
        // through onReconnect, so buffered calls must be flushed there as well.
        $previousOnReconnect = $this->_client->onReconnect;
        $this->_client->onReconnect = function ($client) use ($previousOnReconnect) {
            if (is_callable($previousOnReconnect)) {
                $previousOnReconnect($client);
            }
            $this->flushQueue($client);
        };

        $this->_client->connect();
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if (
            $this->_client->getState() !== StompClient::STATE_ESTABLISHED
            && in_array($name, static::$queueableMethods, true)
        ) {
            if (count($this->_queue) >= static::$maxQueueLength) {
                throw new \RuntimeException(
                    "Stomp queue buffer is full ({$this->_client->_configName}), message not queued"
                );
            }
            $this->_queue[] = [$name, $arguments];

            return null;
        }
        return $this->_client->{$name}(...$arguments);
    }

    /**
     * Underlying STOMP client, mainly for health checks.
     *
     * @return StompClient
     */
    public function getClient(): StompClient
    {
        return $this->_client;
    }

    /**
     * Number of calls waiting for an established connection.
     *
     * @return int
     */
    public function pendingCount(): int
    {
        return count($this->_queue);
    }

    /**
     * Replay buffered calls once the connection is usable again.
     *
     * @param StompClient $client
     * @return void
     */
    protected function flushQueue(StompClient $client): void
    {
        if ($this->_queue === []) {
            return;
        }

        $queue = $this->_queue;
        $this->_queue = [];

        foreach ($queue as [$method, $arguments]) {
            // A re-subscribe is already replayed by workerman; replaying the
            // buffered subscribe would register a second subscription id.
            if (
                ($method === 'subscribe' || $method === 'subscribeWithAck')
                && isset($arguments[0])
                && $client->hasSubscriptionForQueue($arguments[0])
            ) {
                continue;
            }
            $client->{$method}(...$arguments);
        }
    }

    /**
     * @param string $name
     * @return Client
     */
    public static function connection($name = 'default')
    {
        $name = (string)$name;
        if (isset(static::$_connections[$name])) {
            return static::$_connections[$name];
        }

        $config = static::readConfig();
        if (!isset($config[$name]) || !is_array($config[$name])) {
            throw new \RuntimeException("Stomp queue connection {$name} not found");
        }

        if (!isset(Enforcer::$_stompConfig[$name])) {
            Enforcer::initConfig($name, $config[$name]);
        }

        return static::$_connections[$name] = new static($name, Enforcer::$_stompConfig[$name]);
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        return static::connection('default')->{$name}(... $arguments);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function readConfig(): array
    {
        if (!function_exists('config')) {
            throw new \RuntimeException(
                'config() is not available; call Client::connection() inside webman or initialize Enforcer manually'
            );
        }
        $config = config('stomp', []);
        return is_array($config) ? $config : [];
    }
}
