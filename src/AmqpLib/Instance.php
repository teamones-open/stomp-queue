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

class Instance
{

    /**
     * @var Instance
     */
    protected static $_client = null;

    /**
     * @var AMQPStreamConnection
     */
    protected static $_connection = null;

    /**
     * @var AMQPChannel
     */
    protected static $_channel = null;

    protected static $_prefix = "";

    protected static $_amqpConfig = [];

    public function __construct($config)
    {
        self::$_amqpConfig = $config['amqp'];
        $options = $config['options'];
        self::$_prefix = !empty(config("belong_system")) ? config("belong_system") . "." : "";

        self::$_connection = new AMQPStreamConnection(self::$_amqpConfig['host'], self::$_amqpConfig['port'], $options['login'], $options['passcode'], $options['vhost']);
        self::$_channel = self::$_connection->channel();
    }


    /**
     * @param string $name
     * @return Client
     */
    public static function connection($name = 'default')
    {
        $config = config('stomp', []);

        if (!isset($config[$name])) {
            throw new \RuntimeException("RedisQueue connection $name not found");
        }

        if (!isset(static::$_client)) {
            static::$_client = new static($config[$name]);
        }

        return static::$_client;
    }

    /**
     * 创建延迟队列类型交换机
     * @return false|void
     */
    public static function createDelayedExchange()
    {
        if (!isset(self::$_channel)) {
            return false;
        }

        self::$_channel->exchange_declare(self::$_prefix . self::$_amqpConfig['exchange'], 'x-delayed-message', false, true, false, false, false, new AMQPTable(array(
            'x-delayed-type' => AMQPExchangeType::DIRECT
        )));
    }

    /**
     * 绑定队列
     * @param string $queueName
     * @return false|void
     */
    public static function bindQueue($queueName = '')
    {
        if (empty($queueName)) {
            return false;
        }

        // 创建队列
        self::$_channel->queue_declare(self::$_prefix . $queueName, false, true, false, false, false, new AMQPTable([]));

        // 绑定队列
        self::$_channel->queue_bind(self::$_prefix . $queueName, self::$_prefix . self::$_amqpConfig['exchange'], self::$_prefix . $queueName);
    }

    /**
     * 关闭通道和连接
     * @throws \Exception
     */
    public static function close()
    {
        self::$_amqpConfig = [];
        self::$_channel->close();
        self::$_connection->close();
    }
}
