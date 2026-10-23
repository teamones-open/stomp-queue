<?php

declare(strict_types=1);

/**
 * Smoke test against the installed vendor packages.
 *
 * Run after `composer install`: php tests/vendor-smoke.php
 * It verifies that this package loads with the real workerman/stomp and
 * php-amqplib versions and that the overridden methods stay signature
 * compatible with their parents.
 */

error_reporting(E_ALL);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found, run composer install first" . PHP_EOL);
    exit(2);
}
require $autoload;

$failures = [];

function smokeCheck(bool $condition, string $label, string $detail = ''): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $label . ($detail === '' ? '' : ' -> ' . $detail);
    }
}

$classes = [
    \Webman\Stomp\Client::class,
    \Webman\Stomp\StompClient::class,
    \Webman\Stomp\Consumer::class,
    \Webman\Stomp\RetryAbleConsumer::class,
    \Webman\Stomp\AmqpLib\Enforcer::class,
    \Webman\Stomp\Process\Consumer::class,
];

foreach ($classes as $class) {
    smokeCheck(
        class_exists($class) || interface_exists($class),
        "class {$class} loads from the psr-4 map"
    );
}

$parentSubscribe = new \ReflectionMethod(\Workerman\Stomp\Client::class, 'subscribe');
$childSubscribe = new \ReflectionMethod(\Webman\Stomp\StompClient::class, 'subscribe');
smokeCheck(
    $childSubscribe->getNumberOfRequiredParameters() <= $parentSubscribe->getNumberOfRequiredParameters(),
    'subscribe() keeps the parent signature',
    $childSubscribe->getNumberOfRequiredParameters() . ' > ' . $parentSubscribe->getNumberOfRequiredParameters()
);

$parentSend = new \ReflectionMethod(\Workerman\Stomp\Client::class, 'send');
$childSend = new \ReflectionMethod(\Webman\Stomp\StompClient::class, 'send');
smokeCheck(
    $childSend->getNumberOfRequiredParameters() <= $parentSend->getNumberOfRequiredParameters(),
    'send() keeps the parent signature'
);
smokeCheck(
    $childSend->getNumberOfParameters() >= $parentSend->getNumberOfParameters(),
    'send() accepts every parent argument'
);

$amqpConstructor = new \ReflectionMethod(\PhpAmqpLib\Connection\AMQPStreamConnection::class, '__construct');
smokeCheck(
    $amqpConstructor->getNumberOfParameters() >= 14,
    'AMQPStreamConnection accepts the 14 arguments used by Enforcer',
    (string)$amqpConstructor->getNumberOfParameters()
);

foreach (['exchange_declare', 'queue_declare', 'queue_bind'] as $method) {
    smokeCheck(
        method_exists(\PhpAmqpLib\Channel\AMQPChannel::class, $method),
        "AMQPChannel::{$method}() exists"
    );
}

smokeCheck(defined(\Workerman\Stomp\Client::class . '::STATE_ESTABLISHED'), 'STATE_ESTABLISHED constant exists');

\Webman\Stomp\AmqpLib\Enforcer::initConfig('smoke', [
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
        'namespace' => '',
        'exchange_name' => 'exchange',
        'exchange_delay' => true,
    ],
]);
smokeCheck(\Webman\Stomp\AmqpLib\Enforcer::$_namespace['smoke'] === 'smoke', 'namespace is built from the connection name');
smokeCheck(\Webman\Stomp\AmqpLib\Enforcer::$_amqpConfig['smoke']['port'] === 5672, 'amqp port stays an int');

if ($failures !== []) {
    fwrite(STDERR, 'FAIL (' . count($failures) . ')' . PHP_EOL);
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo 'OK vendor smoke on PHP ' . PHP_VERSION
    . ' | workerman/stomp ' . \Composer\InstalledVersions::getPrettyVersion('workerman/stomp')
    . ' | php-amqplib ' . \Composer\InstalledVersions::getPrettyVersion('php-amqplib/php-amqplib')
    . PHP_EOL;
