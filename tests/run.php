<?php

declare(strict_types=1);

/**
 * Dependency-free regression tests.
 *
 * Run with: php tests/run.php
 * Warnings and deprecations are turned into failures so PHP 8.5 deprecations
 * (undefined properties, implicit nullable types, ...) surface here.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__ . '/stubs/Workerman/Stomp/Client.php';
require dirname(__DIR__) . '/src/Consumer.php';
require dirname(__DIR__) . '/src/RetryAbleConsumer.php';
require dirname(__DIR__) . '/src/AmqpLib/Enforcer.php';
require dirname(__DIR__) . '/src/StompClient.php';
require dirname(__DIR__) . '/src/Client.php';
require dirname(__DIR__) . '/src/Process/Consumer.php';

$GLOBALS['__stomp_test_config'] = [
    'default' => [
        'host' => 'stomp://127.0.0.1:61613',
        'options' => [
            'vhost' => '/',
            'login' => 'guest',
            'passcode' => 'guest',
            'debug' => false,
            'heart_beat' => [10000, 10000],
        ],
        'amqp' => [
            'host' => '127.0.0.1',
            'port' => 5672,
            'namespace' => 'im',
            'exchange_name' => 'exchange',
            'exchange_delay' => true,
        ],
    ],
    'no_delay' => [
        'host' => 'stomp://127.0.0.1:61613',
        'options' => [
            'vhost' => '/',
            'login' => 'guest',
            'passcode' => 'guest',
            'debug' => false,
        ],
        'amqp' => [
            'host' => '127.0.0.1',
            'port' => '5672',
            'namespace' => 'im',
            'exchange_name' => 'exchange',
            'exchange_delay' => false,
        ],
    ],
];

function config($name = null, $default = null)
{
    if ($name === 'stomp') {
        return $GLOBALS['__stomp_test_config'];
    }
    return $default;
}

$GLOBALS['__stomp_failures'] = [];
$GLOBALS['__stomp_checks'] = 0;

function check(bool $condition, string $label, string $detail = ''): void
{
    $GLOBALS['__stomp_checks']++;
    if ($condition) {
        return;
    }
    $GLOBALS['__stomp_failures'][] = $label . ($detail === '' ? '' : ' -> ' . $detail);
}

function checkSame($expected, $actual, string $label): void
{
    check(
        $expected === $actual,
        $label,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
}

function checkThrows(string $exceptionClass, callable $callback, string $label, string $messagePart = ''): void
{
    try {
        $callback();
    } catch (\Throwable $e) {
        check($e instanceof $exceptionClass, $label, 'thrown ' . $e::class);
        if ($messagePart !== '') {
            check(str_contains($e->getMessage(), $messagePart), $label . ' (message)', $e->getMessage());
        }
        return;
    }
    check(false, $label, 'nothing thrown');
}

function newStompClient(string $connection = 'default'): \Webman\Stomp\StompClient
{
    $client = new \Webman\Stomp\StompClient('stomp://127.0.0.1:61613');
    $client->_configName = $connection;
    return $client;
}

function invokeProtected(object $object, string $method, array $arguments = [])
{
    // ReflectionMethod::setAccessible() has no effect since 8.1 and is
    // deprecated in 8.5, so it is intentionally not called here.
    return (new \ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
}

// ---------------------------------------------------------------------------
// Config normalization
// ---------------------------------------------------------------------------

\Webman\Stomp\AmqpLib\Enforcer::initConfig('default', $GLOBALS['__stomp_test_config']['default']);
checkSame('im.default', \Webman\Stomp\AmqpLib\Enforcer::$_namespace['default'], 'namespace prefix joins system and connection');
checkSame(5672, \Webman\Stomp\AmqpLib\Enforcer::$_amqpConfig['default']['port'], 'amqp port is normalized to int');
checkSame('guest', \Webman\Stomp\AmqpLib\Enforcer::$_amqpConfig['default']['login'], 'amqp login defaults to stomp login');
checkSame([10000, 10000], \Webman\Stomp\AmqpLib\Enforcer::$_stompConfig['default']['heart_beat'], 'heart_beat is forwarded');
checkSame(false, \Webman\Stomp\AmqpLib\Enforcer::$_stompConfig['default']['debug'], 'debug flag is forwarded');

\Webman\Stomp\AmqpLib\Enforcer::initConfig('no_delay', $GLOBALS['__stomp_test_config']['no_delay']);
checkSame(false, \Webman\Stomp\AmqpLib\Enforcer::$_amqpConfig['no_delay']['exchange_delay'], 'exchange_delay=false is accepted');

\Webman\Stomp\AmqpLib\Enforcer::initConfig('no_debug', [
    'host' => 'stomp://127.0.0.1:61613',
    'options' => ['vhost' => '/', 'login' => 'guest', 'passcode' => 'guest'],
    'amqp' => ['host' => '127.0.0.1', 'port' => 5672, 'exchange_name' => 'exchange', 'exchange_delay' => true],
]);
check(!isset(\Webman\Stomp\AmqpLib\Enforcer::$_stompConfig['no_debug']['debug']), 'debug is optional');

checkThrows(
    \RuntimeException::class,
    static fn() => \Webman\Stomp\AmqpLib\Enforcer::initConfig('bad', ['options' => [], 'amqp' => []]),
    'missing host is rejected',
    'host'
);
checkThrows(
    \RuntimeException::class,
    static fn() => \Webman\Stomp\AmqpLib\Enforcer::initConfig('bad', [
        'host' => 'stomp://127.0.0.1',
        'options' => ['vhost' => '/', 'login' => 'guest'],
        'amqp' => [],
    ]),
    'missing options are rejected',
    'passcode'
);
checkThrows(
    \RuntimeException::class,
    static fn() => \Webman\Stomp\AmqpLib\Enforcer::initConfig('bad', [
        'host' => 'stomp://127.0.0.1',
        'options' => ['vhost' => '/', 'login' => 'guest', 'passcode' => 'guest', 'debug' => false],
        'amqp' => ['host' => '127.0.0.1', 'port' => 5672, 'exchange_name' => 'a/b', 'exchange_delay' => true],
    ]),
    'exchange name with slash is rejected',
    'cannot contain the / symbol'
);
checkThrows(
    \RuntimeException::class,
    static fn() => \Webman\Stomp\AmqpLib\Enforcer::initConfig('bad', [
        'host' => 'stomp://127.0.0.1',
        'options' => ['vhost' => '/', 'login' => 'guest', 'passcode' => 'guest', 'debug' => false],
        'amqp' => ['host' => '127.0.0.1', 'port' => 'abc', 'exchange_name' => 'exchange', 'exchange_delay' => true],
    ]),
    'invalid amqp port is rejected',
    'port'
);

// ---------------------------------------------------------------------------
// Destination building
// ---------------------------------------------------------------------------

$client = newStompClient();
$subscription = $client->subscribe('send_message', static function () {
});
$package = $client->lastPackage();
check(is_string($subscription), 'subscribe returns the subscription id');
checkSame('/amq/queue/im.default.send_message', $package['headers']['destination'] ?? null, 'queue destination is prefixed once');
check(!isset($package['headers']['short_name']), 'short_name is not sent to the broker');
checkSame('auto', $package['headers']['ack'] ?? null, 'ack defaults to auto');
checkSame(1, count($client->subscriptions()), 'subscription is registered');

// workerman re-subscribes with the stored headers after a reconnect
$stored = $client->subscriptions()[$subscription];
$reconnectHeaders = $stored['headers'];
$reconnectHeaders['id'] = $subscription;
$reconnectHeaders['ack'] = $stored['ack'];
$client->subscribe($stored['destination'], static function () {
}, $reconnectHeaders);
checkSame(
    '/amq/queue/im.default.send_message',
    $client->lastPackage()['headers']['destination'],
    'reconnect replay does not prefix the destination twice'
);
checkSame(1, count($client->subscriptions()), 'reconnect replay keeps a single subscription');

$ackClient = newStompClient();
$ackClient->subscribeWithAck('send_sms', static function () {
});
checkSame('/amq/queue/im.default.send_sms', $ackClient->lastPackage()['headers']['destination'], 'subscribeWithAck builds one prefix');
checkSame('client', $ackClient->lastPackage()['headers']['ack'], 'subscribeWithAck forces client ack');

// ---------------------------------------------------------------------------
// Sending
// ---------------------------------------------------------------------------

$sender = newStompClient();
$sender->send('send_sms', 'hello', 5, ['content-type' => 'application/json']);
$package = $sender->lastPackage();
checkSame('/exchange/im.default.exchange/im.default.send_sms', $package['headers']['destination'], 'send targets the exchange with the routing key');
checkSame(5000, $package['headers']['x-delay'], 'delay seconds become x-delay milliseconds');
checkSame(5, $package['headers']['content-length'], 'content-length is the byte length');
checkSame('application/json', $package['headers']['content-type'], 'explicit content-type is preserved');
checkSame('hello', $package['body'], 'body is forwarded');

$sender->send('send_sms', 'plain');
checkSame('text/plain', $sender->lastPackage()['headers']['content-type'], 'content-type defaults to text/plain');
check(!isset($sender->lastPackage()['headers']['x-delay']), 'no x-delay without a delay');

checkThrows(
    \InvalidArgumentException::class,
    static fn() => $sender->send('send_sms', ['not' => 'a string']),
    'non-string body is rejected'
);
checkThrows(
    \RuntimeException::class,
    static fn() => (newStompClient('no_delay'))->send('send_sms', 'body', 5),
    'delay without a delayed exchange is rejected',
    'exchange_delay'
);
checkThrows(
    \RuntimeException::class,
    static fn() => $sender->send('', 'body'),
    'empty queue name is rejected'
);

// ---------------------------------------------------------------------------
// Connection buffering
// ---------------------------------------------------------------------------

$connection = \Webman\Stomp\Client::connection('default');
$stompClient = $connection->getClient();
$stompClient->packages = [];

$stompClient->setState(\Webman\Stomp\StompClient::STATE_INITIAL);
checkSame(null, $connection->send('send_sms', 'buffered'), 'send returns null while disconnected');
checkSame(0, count($stompClient->packages), 'nothing is written before the connection is established');

$onConnect = $stompClient->onConnect;
$onConnect($stompClient);
checkSame(1, count($stompClient->packages), 'buffered send is flushed on connect');
checkSame('buffered', $stompClient->lastPackage()['body'], 'flushed body is intact');

$stompClient->setState(\Webman\Stomp\StompClient::STATE_ESTABLISHED);
$connection->subscribe('reconnect_queue', static function () {
});
$sentBeforeReconnect = count($stompClient->packages);
$stompClient->setState(\Webman\Stomp\StompClient::STATE_INITIAL);
$connection->subscribe('reconnect_queue', static function () {
});
$onReconnect = $stompClient->onReconnect;
$onReconnect($stompClient);
checkSame($sentBeforeReconnect, count($stompClient->packages), 'reconnect does not register a duplicate subscription');
check($stompClient->hasSubscriptionForQueue('reconnect_queue'), 'subscription survives reconnect');
checkSame(0, $connection->pendingCount(), 'buffer is empty after flush');

checkThrows(
    \RuntimeException::class,
    static fn() => \Webman\Stomp\Client::connection('missing'),
    'unknown connection is rejected',
    'not found'
);

// ---------------------------------------------------------------------------
// Process\Consumer metadata handling
// ---------------------------------------------------------------------------

$process = new \Webman\Stomp\Process\Consumer('/tmp/consumers');
checkSame('auto', invokeProtected($process, 'ackOf', [new \stdClass()]), 'ack defaults to auto');
checkSame(10, invokeProtected($process, 'prefetchOf', [new \stdClass()]), 'prefetch defaults to 10');
checkSame(['a', 'b'], invokeProtected($process, 'queuesOf', [new class {
    public $queue = ['a', 'b', 'a'];
}]), 'queue list is normalized and de-duplicated');
checkThrows(
    \RuntimeException::class,
    static fn() => invokeProtected($process, 'ackOf', [new class {
        public $ack = 'sometimes';
    }]),
    'invalid ack mode is rejected'
);
checkThrows(
    \RuntimeException::class,
    static fn() => invokeProtected($process, 'queuesOf', [new \stdClass()]),
    'missing queue is rejected'
);
checkSame(
    'app\\queue\\stomp\\SendSmsConsumer',
    invokeProtected($process, 'classFromFile', ['D:\\app\\app\\queue\\stomp\\SendSmsConsumer.php', 'D:\\app\\']),
    'consumer class is derived from the file path'
);

// ---------------------------------------------------------------------------
// Result
// ---------------------------------------------------------------------------

$failures = $GLOBALS['__stomp_failures'];
$checks = $GLOBALS['__stomp_checks'];

if ($failures !== []) {
    fwrite(STDERR, "FAIL ({$checks} checks, " . count($failures) . " failed)" . PHP_EOL);
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "OK ({$checks} checks) on PHP " . PHP_VERSION . PHP_EOL;
