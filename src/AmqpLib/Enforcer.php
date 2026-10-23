<?php

declare(strict_types=1);

namespace Webman\Stomp\AmqpLib;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Declares the AMQP topology (exchange / queue / binding) used by the STOMP client.
 *
 * STOMP cannot declare an exchange, so the topology is created once per worker
 * through php-amqplib and the AMQP connection is released afterwards.
 */
class Enforcer
{
    /**
     * @var array<string, self>
     */
    protected static array $_client = [];

    /**
     * Prefix shared by exchange, queue and routing key.
     *
     * @var array<string, string>
     */
    public static array $_namespace = [];

    /**
     * Normalized options forwarded to workerman/stomp.
     *
     * @var array<string, array<string, mixed>>
     */
    public static array $_stompConfig = [];

    /**
     * Normalized AMQP connection options.
     *
     * @var array<string, array<string, mixed>>
     */
    public static array $_amqpConfig = [];

    /**
     * Options accepted from config. Unknown keys are ignored instead of being
     * forwarded to the STOMP client.
     *
     * @var string[]
     */
    protected static array $optionsAllowKey = [
        'vhost', 'login', 'passcode', 'bindto', 'ssl', 'connect_timeout', 'reconnect_period', 'debug', 'heart_beat',
    ];

    /**
     * @var string[]
     */
    protected static array $stompOptionRequired = [
        'vhost', 'login', 'passcode',
    ];

    /**
     * @var string[]
     */
    protected static array $amqpRequiredKey = [
        'host', 'port', 'exchange_name', 'exchange_delay',
    ];

    protected string $name;

    protected AMQPStreamConnection $connection;

    protected AMQPChannel $channel;

    public function __construct(string $name, array $config)
    {
        self::initConfig($name, $config);

        $amqp = self::$_amqpConfig[$name];

        $this->name = $name;
        $this->connection = new AMQPStreamConnection(
            $amqp['host'],
            $amqp['port'],
            $amqp['login'],
            $amqp['passcode'],
            $amqp['vhost'],
            false,
            'AMQPLAIN',
            null,
            'en_US',
            $amqp['connection_timeout'],
            $amqp['read_write_timeout'],
            null,
            false,
            $amqp['heartbeat']
        );
        $this->channel = $this->connection->channel();

        self::$_client[$name] = $this;
    }

    /**
     * Normalize and validate a connection config block.
     *
     * @param string $name
     * @param array $config
     * @return void
     */
    public static function initConfig(string $name, array $config = []): void
    {
        if ($name === '') {
            throw new \RuntimeException('Stomp connection name cannot be empty');
        }

        if (empty($config['host']) || !is_string($config['host'])) {
            throw new \RuntimeException('Stomp host config does not exist');
        }
        $stompConfig = ['host' => $config['host']];

        $options = $config['options'] ?? null;
        if (!is_array($options) || $options === []) {
            throw new \RuntimeException('Stomp options config does not exist');
        }
        foreach (self::$stompOptionRequired as $optionKey) {
            if (!array_key_exists($optionKey, $options)) {
                throw new \RuntimeException("Stomp options {$optionKey} config does not exist");
            }
        }
        foreach (self::$optionsAllowKey as $optionKey) {
            if (!array_key_exists($optionKey, $options) || $options[$optionKey] === null) {
                continue;
            }
            $value = $options[$optionKey];
            if ($optionKey === 'heart_beat') {
                $value = is_array($value)
                    ? [(int)($value[0] ?? 0), (int)($value[1] ?? 0)]
                    : [(int)$value, 0];
            } elseif ($optionKey === 'ssl') {
                if (!is_array($value)) {
                    continue;
                }
            } elseif ($optionKey === 'debug') {
                $value = (bool)$value;
            } elseif ($optionKey === 'connect_timeout' || $optionKey === 'reconnect_period') {
                $value = (float)$value;
            } elseif (!is_string($value)) {
                continue;
            }
            $stompConfig[$optionKey] = $value;
        }

        $amqp = $config['amqp'] ?? null;
        if (!is_array($amqp) || $amqp === []) {
            throw new \RuntimeException('Amqp config does not exist');
        }
        foreach (self::$amqpRequiredKey as $amqpKey) {
            if (!array_key_exists($amqpKey, $amqp)) {
                throw new \RuntimeException("Stomp options {$amqpKey} config does not exist");
            }
        }
        if (!is_string($amqp['host']) || trim($amqp['host']) === '') {
            throw new \RuntimeException('Stomp options host config does not exist');
        }
        $port = filter_var($amqp['port'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new \RuntimeException('Stomp options port config must be an integer between 1 and 65535');
        }
        if (!is_string($amqp['exchange_name']) || trim($amqp['exchange_name']) === '') {
            throw new \RuntimeException('Stomp options exchange_name config does not exist');
        }
        if (str_contains($amqp['exchange_name'], '/')) {
            throw new \RuntimeException("exchange name {$amqp['exchange_name']} cannot contain the / symbol");
        }

        $namespacePrefix = [];
        if (!empty($amqp['namespace']) && is_string($amqp['namespace'])) {
            $namespacePrefix[] = trim($amqp['namespace']);
        }
        $namespacePrefix[] = $name;

        self::$_namespace[$name] = implode('.', $namespacePrefix);
        self::$_stompConfig[$name] = $stompConfig;
        self::$_amqpConfig[$name] = [
            'host' => $amqp['host'],
            'port' => $port,
            'exchange_name' => $amqp['exchange_name'],
            'exchange_delay' => (bool)$amqp['exchange_delay'],
            // AMQP credentials default to the STOMP ones, but may be overridden
            // when the broker uses different users per protocol.
            'login' => (string)($amqp['login'] ?? $stompConfig['login'] ?? 'guest'),
            'passcode' => (string)($amqp['passcode'] ?? $stompConfig['passcode'] ?? 'guest'),
            'vhost' => (string)($amqp['vhost'] ?? $stompConfig['vhost'] ?? '/'),
            'connection_timeout' => (float)($amqp['connection_timeout'] ?? 3.0),
            'read_write_timeout' => (float)($amqp['read_write_timeout'] ?? 3.0),
            'heartbeat' => (int)($amqp['heartbeat'] ?? 0),
        ];
    }

    /**
     * @param string $name
     * @return self
     */
    public static function connection(string $name = 'default'): self
    {
        if (isset(self::$_client[$name])) {
            return self::$_client[$name];
        }

        $config = self::readConfig();
        if (!isset($config[$name]) || !is_array($config[$name])) {
            throw new \RuntimeException("Stomp connection {$name} not found");
        }

        return new static($name, $config[$name]);
    }

    /**
     * Channel of an initialized connection.
     *
     * @param string $name
     * @return AMQPChannel
     */
    public static function channel(string $name = 'default'): AMQPChannel
    {
        return self::connection($name)->getChannel();
    }

    /**
     * Declare the exchange, using the delayed message plugin when enabled.
     *
     * @param string $name
     * @return void
     */
    public static function createDelayedExchange(string $name = 'default'): void
    {
        $channel = self::channel($name);
        $amqp = self::amqpConfig($name);

        $exchangeType = AMQPExchangeType::DIRECT;
        $arguments = [];
        if ($amqp['exchange_delay']) {
            $exchangeType = 'x-delayed-message';
            $arguments = ['x-delayed-type' => AMQPExchangeType::DIRECT];
        }

        try {
            $channel->exchange_declare(
                self::exchangeName($name),
                $exchangeType,
                false,
                true,
                false,
                false,
                false,
                new AMQPTable($arguments)
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Failed to declare exchange " . self::exchangeName($name) . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Declare the queue and bind it to the exchange.
     *
     * @param string $name
     * @param string $queueName
     * @return void
     */
    public static function createAndBindQueue(string $name = 'default', string $queueName = ''): void
    {
        $queueName = trim($queueName);
        if ($queueName === '') {
            throw new \RuntimeException('Stomp queue name cannot be empty');
        }
        if (str_contains($queueName, '/') || preg_match('/\s/', $queueName) === 1) {
            throw new \RuntimeException("Stomp queue name {$queueName} cannot contain / or whitespace");
        }

        $channel = self::channel($name);
        self::amqpConfig($name);

        $queue = self::$_namespace[$name] . '.' . $queueName;
        $exchange = self::exchangeName($name);

        $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([]));
        $channel->queue_bind($queue, $exchange, $queue);
    }

    /**
     * Close the AMQP channel/connection of one connection. Safe to call twice.
     *
     * @param string $name
     * @return void
     */
    public static function destroy(string $name = 'default'): void
    {
        $instance = self::$_client[$name] ?? null;
        unset(self::$_client[$name]);

        if ($instance === null) {
            return;
        }

        try {
            $instance->channel->close();
        } catch (\Throwable) {
            // Connection may already be gone; nothing left to release.
        }
        try {
            $instance->connection->close();
        } catch (\Throwable) {
            // Ignore shutdown errors so a worker can keep consuming over STOMP.
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConnection(): AMQPStreamConnection
    {
        return $this->connection;
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    /**
     * @param string $name
     * @return array<string, mixed>
     */
    protected static function amqpConfig(string $name): array
    {
        if (!isset(self::$_amqpConfig[$name])) {
            throw new \RuntimeException("Stomp connection {$name} is not initialized");
        }
        return self::$_amqpConfig[$name];
    }

    protected static function exchangeName(string $name): string
    {
        if (!isset(self::$_namespace[$name])) {
            throw new \RuntimeException("Stomp connection {$name} is not initialized");
        }
        return self::$_namespace[$name] . '.' . self::amqpConfig($name)['exchange_name'];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function readConfig(): array
    {
        if (!function_exists('config')) {
            throw new \RuntimeException(
                'config() is not available; initialize Stomp connections explicitly or call within webman'
            );
        }
        $config = config('stomp', []);
        return is_array($config) ? $config : [];
    }
}
