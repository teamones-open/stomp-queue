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

namespace Webman\Stomp\Exchange;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

class Exchange
{
    /**
     * 注册延迟队列类型交换机
     * @throws \Exception
     */
    public static function register($name = 'default')
    {
        $config = config('stomp', []);

        if (!isset($config[$name])) {
            throw new \RuntimeException("RedisQueue connection $name not found");
        }

        $amqp = $config[$name]['amqp'];
        $options = $config[$name]['options'];
        $connection = new AMQPStreamConnection($amqp['host'], $amqp['port'], $options['login'], $options['passcode'], $options['vhost']);


        $channel = $connection->channel();
        $channel->exchange_declare($amqp['exchange'], 'x-delayed-message', false, true, false, false, false, new AMQPTable(array(
            'x-delayed-type' => AMQPExchangeType::DIRECT
        )));

        $channel->close();
        $connection->close();
    }
}
