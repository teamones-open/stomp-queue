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

namespace Webman\Stomp\Process;

use http\Exception\RuntimeException;
use Webman\Stomp\Client;
use Webman\Stomp\AmqpLib\Enforcer;

/**
 * Class StompConsumer
 * @package process
 */
class Consumer
{
    /**
     * @var string
     */
    protected $_consumerDir = '';

    /**
     * StompConsumer constructor.
     * @param string $consumer_dir
     */
    public function __construct($consumer_dir = '')
    {
        $this->_consumerDir = $consumer_dir;
    }

    /**
     * onWorkerStart.
     * @throws \Exception
     */
    public function onWorkerStart()
    {
        $dir_iterator = new \RecursiveDirectoryIterator($this->_consumerDir);
        $iterator = new \RecursiveIteratorIterator($dir_iterator);


        // Get consumers by connection name
        $connectionList = [];
        foreach ($iterator as $file) {
            if (is_dir($file)) {
                continue;
            }
            $fileinfo = new \SplFileInfo($file);
            $ext = $fileinfo->getExtension();
            if ($ext === 'php') {
                $class = str_replace('/', "\\", substr(substr($file, strlen(base_path())), 0, -4));
                if (!is_a($class, 'Webman\Stomp\Consumer', true)) {
                    continue;
                }
                if (class_exists("support\bootstrap\Container")) {
                    // 兼容老版文件位置
                    $consumer = \support\bootstrap\Container::get($class);
                } elseif (class_exists("support\Container")) {
                    // 新版webman移动了文件位置
                    $consumer = \support\Container::get($class);
                } else {
                    throw new RuntimeException('Container file not find.');
                }

                $connectionName = $consumer->connection ?? 'default';
                $connectionList[$connectionName][] = $consumer;
            }
        }

        // create custom exchange by php-amqplib/php-amqplib
        foreach ($connectionList as $connectionName => $consumers) {
            $amqpEnforcer = Enforcer::connection($connectionName);
            $amqpEnforcer::createDelayedExchange($connectionName);

            foreach ($consumers as $consumer) {
                $queue = $consumer->queue;
                $ack = $consumer->ack ?? 'auto';

                // create and bind queue
                $amqpEnforcer::createAndBindQueue($connectionName, $queue);

                $connection = Client::connection($connectionName);
                $cb = function ($client, $package, $ack) use ($consumer) {
                    \call_user_func([$consumer, 'consume'], $package['body'], $ack, $client);
                };
                $connection->subscribe($queue, $cb, ['ack' => $ack]);
            }

            // destroy current amqp connection
            $amqpEnforcer::destroy($connectionName);
        }

        unset($connectionList);
    }
}