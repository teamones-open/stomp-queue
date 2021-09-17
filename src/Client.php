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

use Webman\Stomp\AmqpLib\Enforcer;

/**
 * Class Stomp
 * @package support
 *
 * Strings methods
 * @method static mixed subscribe(string $queueName, callback $callback, array $headers = [])
 * @method static void send(string $queueName, $body, int $delay = 0, array $headers = [])
 */
class Client
{

    /**
     * @var Client[]
     */
    protected static $_connections = null;

    /**
     * @var array
     */
    protected $_queue = [];

    /**
     * @var StompClient
     */
    protected $_client;


    /**
     * @param $name
     * @param array $config
     */
    public function __construct($name, $config = [])
    {
        $this->_client = new StompClient($config['host'], $config);
        $this->_client->_configName = $name;
        $this->_client->onConnect = function ($client) {
            foreach ($this->_queue as $item) {
                $client->{$item[0]}(... $item[1]);
            }
            $this->_queue = [];
        };
        $this->_client->connect();
    }

    /**
     * @param $name
     * @param $arguments
     *
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if ($this->_client->getState() != StompClient::STATE_ESTABLISHED) {
            if (in_array($name, [
                'subscribe',
                'subscribeWithAck',
                'unsubscribe',
                'send',
                'ack',
                'nack',
                'disconnect'])) {
                $this->_queue[] = [$name, $arguments];
                
                return null;
            }
        }
        return $this->_client->{$name}(...$arguments);
    }

    /**
     * @param string $name
     * @return Client
     */
    public static function connection($name = 'default')
    {
        if (!isset(static::$_connections[$name])) {

            $config = config('stomp', []);
            if (!isset($config[$name])) {
                throw new \RuntimeException("Stomp Queue connection $name not found");
            }

            if (!isset(Enforcer::$_stompConfig[$name])) {
                Enforcer::initConfig($name, $config[$name]);
            }

            $client = new static($name, Enforcer::$_stompConfig[$name]);
            static::$_connections[$name] = $client;
        }
        return static::$_connections[$name];
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        return static::connection('default')->{$name}(... $arguments);
    }
}
