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

namespace Webman\Stomp\AmqpLib;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

class Enforcer
{

    /**
     * @var Enforcer[]
     */
    protected static $_client = [];

    /**
     * @var AMQPStreamConnection
     */
    protected static $_connection = null;

    /**
     * @var AMQPChannel
     */
    protected static $_channel = null;

    /**
     * @var array
     */
    public static $_namespace = [];

    /**
     * @var array|mixed
     */
    public static $_stompConfig = [];

    /**
     * @var array|mixed
     */
    public static $_amqpConfig = [];

    /**
     * option允许写入的键
     * @var string[]
     */
    protected static $optionsAllowKey = [
        'vhost', 'login', 'passcode', 'bindto', 'ssl', 'connect_timeout', 'reconnect_period', 'debug', 'heart_beat'
    ];

    /**
     * 必传配置
     * @var string[]
     */
    protected static $stompOptionRequired = [
        'vhost', 'login', 'passcode', 'debug',
    ];

    /**
     * @param $config
     */
    public function __construct(string $name, array $config)
    {
        // init config param
        self::initConfig($name, $config);

        // init amqp connection
        self::$_connection = new AMQPStreamConnection(
            self::$_amqpConfig[$name]['host'],
            self::$_amqpConfig[$name]['port'],
            self::$_amqpConfig[$name]['login'],
            self::$_amqpConfig[$name]['passcode'],
            self::$_amqpConfig[$name]['vhost']
        );

        // amqp  channel
        self::$_channel = self::$_connection->channel();
    }


    /**
     * init config
     * @param string $name
     * @param array $config
     */
    public static function initConfig(string $name, array $config = [])
    {
        // stomp config
        $stompConfig = [];

        // amqp config
        $amqpConfig = [];

        if (empty($config['host'])) {
            throw new \RuntimeException("Stomp host config does not exist");
        }
        $stompConfig['host'] = $config['host'];

        if (empty($config['options'])) {
            throw new \RuntimeException("Stomp options config does not exist");
        }
        foreach (self::$optionsAllowKey as $optionKey) {
            if (
                in_array($optionKey, self::$stompOptionRequired)
                && !array_key_exists($optionKey, $config['options'])
            ) {
                // 必传配置没传检查
                throw new \RuntimeException("Stomp options {$optionKey} config does not exist");
            } else if (!isset($config['options'][$optionKey])) {
                // 配置没传
                continue;
            } else {
                $stompConfig[$optionKey] = $config['options'][$optionKey];
                if (in_array($optionKey, ['vhost', 'login', 'passcode'])) {
                    $amqpConfig[$optionKey] = $config['options'][$optionKey];
                }
            }
        }

        if (empty($config['amqp'])) {
            throw new \RuntimeException("Amqp config does not exist");
        }
        foreach (['host', 'port', 'exchange_name', 'exchange_delay'] as $amqpKey) {
            if (!array_key_exists($amqpKey, $config['amqp'])
                || empty($config['amqp'][$amqpKey])
                && $config['amqp'][$amqpKey] !== false
            ) {
                throw new \RuntimeException("Stomp options {$amqpKey} config does not exist");
            }
            if ($amqpKey === 'exchange_name' && strpos($config['amqp'][$amqpKey], '/') !== false) {
                throw new \RuntimeException("exchange name {$config['amqp'][$amqpKey]} cannot contain the / symbol");
            }
            $amqpConfig[$amqpKey] = $config['amqp'][$amqpKey];
        }

        // get namespace
        $namespacePrefix = [];
        if (!empty($config['amqp']['namespace'])) {
            $namespacePrefix[] = $config['amqp']['namespace'];
        }
        $namespacePrefix[] = $name;
        // user custom name + connect name
        self::$_namespace[$name] = join('.', $namespacePrefix);


        self::$_stompConfig[$name] = $stompConfig;
        self::$_amqpConfig[$name] = $amqpConfig;
    }

    /**
     * @param string $name
     * @return Enforcer
     */
    public static function connection(string $name = 'default')
    {
        $config = config('stomp', []);

        if (!isset($config[$name])) {
            throw new \RuntimeException("RedisQueue connection $name not found");
        }

        if (!isset(static::$_client[$name])) {
            static::$_client[$name] = new static($name, $config[$name]);
        }

        return static::$_client[$name];
    }

    /**
     * 创建延迟队列类型交换机
     * @param string $name
     * @return false|void
     */
    public static function createDelayedExchange(string $name = 'default')
    {
        if (!isset(self::$_channel) && !isset(self::$_amqpConfig[$name])) {
            return false;
        }

        $exchangeType = AMQPExchangeType::DIRECT;
        $argument = [];
        if (self::$_amqpConfig[$name]['exchange_delay']) {
            // is delay message type
            $exchangeType = 'x-delayed-message';

            $argument = [
                'x-delayed-type' => AMQPExchangeType::DIRECT
            ];
        }

        try {
            self::$_channel->exchange_declare(
                self::$_namespace[$name] . '.' . self::$_amqpConfig[$name]['exchange_name'],
                $exchangeType,
                false,
                true,
                false,
                false,
                false,
                new AMQPTable($argument)
            );
        } catch (\Exception $e) {
            throw new \RuntimeException($e->getMessage());
        }
    }

    /**
     * 绑定队列
     * @param string $name
     * @param string $queueName
     * @return false|void
     */
    public static function createAndBindQueue(string $name = 'default', string $queueName = '')
    {
        if (empty($queueName) && !isset(self::$_channel) && !isset(self::$_amqpConfig[$name])) {
            return false;
        }

        // create queue
        self::$_channel->queue_declare(
            self::$_namespace[$name] . '.' . $queueName,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([])
        );

        // queue bind exchange
        self::$_channel->queue_bind(
            self::$_namespace[$name] . '.' . $queueName,
            self::$_namespace[$name] . '.' . self::$_amqpConfig[$name]['exchange_name'],
            self::$_namespace[$name] . '.' . $queueName
        );
    }

    /**
     * 关闭通道和连接
     * @param string $name
     * @throws \Exception
     */
    public static function destroy(string $name = 'default')
    {
        self::$_channel->close();
        self::$_connection->close();

        self::$_channel = null;
        self::$_connection = null;

        unset(self::$_client[$name]);
    }
}
