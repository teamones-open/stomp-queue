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
     * Broker ERROR frames seen in the current window.
     */
    protected int $_errorCount = 0;

    protected float $_lastErrorAt = 0.0;

    /**
     * When the last reconnect was scheduled, used to coalesce ERROR bursts
     * (one broken queue can produce one ERROR per subscription).
     */
    protected float $_lastReconnectAt = 0.0;

    /**
     * Guard against reconnecting forever on a permanent broker error.
     * Override per connection with the optional `max_reconnects` config key.
     */
    protected int $_maxReconnectsPerWindow = 3;

    protected static int $errorWindowSeconds = 60;

    protected static int $reconnectDebounceSeconds = 2;

    protected static int $reconnectDelay = 2;

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
            $this->_errorCount = 0;
            $this->_lastReconnectAt = 0.0;
            $this->flushQueue($client);
        };

        // workerman re-subscribes known destinations on reconnect and reports it
        // through onReconnect, so buffered calls must be flushed there as well.
        $previousOnReconnect = $this->_client->onReconnect;
        $this->_client->onReconnect = function ($client) use ($previousOnReconnect) {
            $this->_errorCount = 0;
            $this->_lastReconnectAt = 0.0;
            if (is_callable($previousOnReconnect)) {
                $previousOnReconnect($client);
            }
            $this->flushQueue($client);
        };

        // Without a handler workerman only echoes broker errors to stdout.
        $this->_client->onError = function ($error) use ($name) {
            $this->handleClientError($error, (string)$name);
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
     * Override the broker-error reconnect budget for this connection.
     *
     * @param int $max
     * @return void
     */
    public function setMaxReconnects(int $max): void
    {
        if ($max < 1) {
            throw new \InvalidArgumentException('max reconnects must be a positive integer');
        }
        $this->_maxReconnectsPerWindow = $max;
    }

    /**
     * Log broker/connection errors and recover from broker ERROR frames.
     *
     * Connection-level failures are already retried by workerman's onClose;
     * an ERROR frame (for example "Server cancelled subscription") leaves the
     * connection open, so subscriptions have to be restored explicitly.
     *
     * @param mixed $error
     * @param string $name
     * @return void
     */
    protected function handleClientError($error, string $name): void
    {
        $message = $error instanceof \Throwable ? $error->getMessage() : (string)$error;
        Logger::error("connection {$name}: {$message}");

        if (!$error instanceof \Workerman\Stomp\Exception) {
            return;
        }
        if ($this->_client->getState() !== StompClient::STATE_ESTABLISHED) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->_lastErrorAt > static::$errorWindowSeconds) {
            $this->_errorCount = 0;
        }
        $this->_lastErrorAt = $now;

        // One incident (for example deleting a queue) yields one ERROR per
        // subscription; coalesce the burst into a single reconnect.
        if ($now - $this->_lastReconnectAt < static::$reconnectDebounceSeconds) {
            Logger::error("connection {$name}: broker error within debounce window, reconnect already scheduled");
            return;
        }

        if (++$this->_errorCount > $this->_maxReconnectsPerWindow) {
            Logger::error("connection {$name}: too many broker errors, auto-reconnect stopped");
            return;
        }

        $this->_lastReconnectAt = $now;
        Logger::error("connection {$name}: reconnecting after broker error");
        $this->_client->reconnect(static::$reconnectDelay);
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

        $maxReconnects = $config[$name]['max_reconnects'] ?? null;
        if ($maxReconnects !== null && (!is_numeric($maxReconnects) || (int)$maxReconnects < 1)) {
            throw new \RuntimeException(
                "Stomp queue connection {$name} max_reconnects must be a positive integer"
            );
        }

        $client = new static($name, Enforcer::$_stompConfig[$name]);
        if ($maxReconnects !== null) {
            $client->setMaxReconnects((int)$maxReconnects);
        }

        return static::$_connections[$name] = $client;
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
