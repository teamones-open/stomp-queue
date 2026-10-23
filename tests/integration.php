<?php

declare(strict_types=1);

/**
 * Integration test against a real RabbitMQ (STOMP 61613 + AMQP 5672 + delayed exchange plugin).
 *
 * Required env:
 *   STOMP_HOST, STOMP_PORT, AMQP_HOST, AMQP_PORT, RABBITMQ_USER, RABBITMQ_PASS, RABBITMQ_VHOST
 *
 * Run: php tests/integration.php start
 *
 * The test uses a unique namespace (stomp3x_it_*) and deletes its queues/exchange afterwards.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/vendor/autoload.php';

use Webman\Stomp\AmqpLib\Enforcer;
use Webman\Stomp\Client;
use Webman\Stomp\Consumer;
use Webman\Stomp\Logger;
use Webman\Stomp\Process\Consumer as ConsumerProcess;
use Webman\Stomp\RetryAbleConsumer;
use Webman\Stomp\StompClient;
use Workerman\Timer;
use Workerman\Worker;

Logger::setHandler(static function (string $message): void {
    echo '[log] ' . $message . PHP_EOL;
});

// Keep workerman's own log out of the repository.
$itTmpDir = dirname(__DIR__) . '/.teones/tmp';
@mkdir($itTmpDir, 0777, true);
Worker::$logFile = $itTmpDir . '/workerman.log';

function it_env(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

$namespace = 'stomp3x_it_' . substr((string)getmypid(), -5) . '_' . date('His');
$queues = ['it_normal', 'it_delay', 'it_retry'];

$GLOBALS['IT_CONFIG'] = [
    'default' => [
        'host' => 'stomp://' . it_env('STOMP_HOST', 'rabbitmq') . ':' . it_env('STOMP_PORT', '61613'),
        'options' => [
            'vhost' => it_env('RABBITMQ_VHOST', '/'),
            'login' => it_env('RABBITMQ_USER', 'guest'),
            'passcode' => it_env('RABBITMQ_PASS', 'guest'),
            'debug' => false,
            'heart_beat' => [10000, 10000],
        ],
        'amqp' => [
            'host' => it_env('AMQP_HOST', 'rabbitmq'),
            'port' => (int)it_env('AMQP_PORT', '5672'),
            'namespace' => $namespace,
            'exchange_name' => 'exchange',
            'exchange_delay' => true,
            'connection_timeout' => 5.0,
            'read_write_timeout' => 10.0,
            // exercises the configurable topology arguments
            'queue_arguments' => ['x-message-ttl' => 60000],
        ],
    ],
];

$GLOBALS['IT'] = [
    'namespace' => $namespace,
    'queues' => $queues,
    'hits' => ['it_normal' => 0, 'it_delay' => 0, 'it_retry' => 0],
    'bodies' => [],
    'delay_sent_at' => 0.0,
    'delay_elapsed' => null,
    'settle_at' => 0.0,
    'finished' => false,
    'notes' => [],
    'errors' => [],
];

function config($name = null, $default = null)
{
    if ($name === 'stomp') {
        return $GLOBALS['IT_CONFIG'];
    }
    return $default;
}

final class ItNormalConsumer implements Consumer
{
    public $queue = 'it_normal';
    public $ack = 'client';

    public function consume($data, $ackResolver = null, $client = null)
    {
        $GLOBALS['IT']['hits']['it_normal']++;
        $GLOBALS['IT']['bodies']['it_normal'] = $data;
        if ($ackResolver !== null) {
            $ackResolver->ack();
        }
    }
}

final class ItDelayConsumer implements Consumer
{
    public $queue = 'it_delay';
    public $ack = 'client';

    public function consume($data, $ackResolver = null, $client = null)
    {
        $GLOBALS['IT']['hits']['it_delay']++;
        $GLOBALS['IT']['bodies']['it_delay'] = $data;
        $GLOBALS['IT']['delay_elapsed'] = microtime(true) - $GLOBALS['IT']['delay_sent_at'];
        if ($ackResolver !== null) {
            $ackResolver->ack();
        }
    }
}

final class ItRetryConsumer implements Consumer, RetryAbleConsumer
{
    public $queue = 'it_retry';
    public $ack = 'client';

    public function consume($data, $ackResolver = null, $client = null)
    {
        $GLOBALS['IT']['hits']['it_retry']++;
        $GLOBALS['IT']['bodies']['it_retry'] = $data;
        if ($GLOBALS['IT']['hits']['it_retry'] === 1) {
            throw new \RuntimeException('integration: fail on purpose');
        }
        if ($ackResolver !== null) {
            $ackResolver->ack();
        }
    }

    public function getTtr()
    {
        return 1;
    }

    public function canRetry($attempt, $error)
    {
        return $attempt < 3;
    }
}

$worker = new Worker();
$worker->name = 'stomp-it';
$worker->count = 1;

$worker->onWorkerStart = function () use ($namespace, $queues): void {
    $finish = function (bool $timeout = false) use ($namespace): void {
        if ($GLOBALS['IT']['finished']) {
            return;
        }
        $GLOBALS['IT']['finished'] = true;

        $it = $GLOBALS['IT'];
        $delayElapsed = $it['delay_elapsed'] === null ? 0.0 : (float)$it['delay_elapsed'];

        $checks = [
            'normal delivery once' => $it['hits']['it_normal'] === 1,
            'normal body intact' => ($it['bodies']['it_normal'] ?? null) === 'normal-body',
            'delayed delivery once' => $it['hits']['it_delay'] === 1,
            'delayed body intact' => ($it['bodies']['it_delay'] ?? null) === 'delay-body',
            'delay honoured (>=1.5s)' => $delayElapsed >= 1.5,
            'delay not excessive (<=10s)' => $delayElapsed <= 10.0,
            'retry attempted twice' => $it['hits']['it_retry'] === 2,
            'retry body intact' => ($it['bodies']['it_retry'] ?? null) === 'retry-body',
            'no timeout' => $timeout === false,
        ];

        // teardown: queue deletion makes the broker cancel subscriptions, which
        // would otherwise trigger the client's reconnect path.
        try {
            Client::connection('default')->getClient()->onError = static function (): void {
            };
        } catch (\Throwable) {
        }

        // cleanup topology so repeated runs do not leave queues behind
        $cleanupErrors = [];
        try {
            Enforcer::connection('default');
            $channel = Enforcer::channel('default');
            foreach ($GLOBALS['IT']['queues'] as $queue) {
                $channel->queue_delete($namespace . '.default.' . $queue);
            }
            $channel->exchange_delete($namespace . '.default.exchange');
        } catch (\Throwable $e) {
            $cleanupErrors[] = $e->getMessage();
        } finally {
            try {
                Enforcer::destroy('default');
            } catch (\Throwable) {
            }
        }

        $pass = !in_array(false, $checks, true) && $cleanupErrors === [];
        $result = [
            'pass' => $pass,
            'timeout' => $timeout,
            'namespace' => $namespace,
            'hits' => $it['hits'],
            'delay_elapsed' => round($delayElapsed, 3),
            'checks' => $checks,
            'notes' => $it['notes'],
            'errors' => array_merge($it['errors'], $cleanupErrors),
        ];

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $dir = dirname(__DIR__) . '/.teones/tmp';
        @mkdir($dir, 0777, true);
        $out = $dir . '/it-result-' . $namespace . '.json';
        @file_put_contents($out, $json);
        @file_put_contents($dir . '/it-result-latest.json', $json);

        echo "\n--- integration result ---\n";
        foreach ($checks as $label => $ok) {
            echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
        }
        if ($cleanupErrors !== []) {
            echo '[FAIL] cleanup: ' . implode('; ', $cleanupErrors) . PHP_EOL;
        }
        echo 'hits=' . json_encode($it['hits']) . ' delay=' . round($delayElapsed, 3) . 's' . PHP_EOL;
        echo 'RESULT: ' . ($pass ? 'PASS' : 'FAIL') . PHP_EOL;
        echo 'result-file: ' . $out . PHP_EOL;

        posix_kill(posix_getppid(), SIGINT);
    };

    try {
        // 1. declare topology over AMQP
        Enforcer::connection('default');
        Enforcer::createDelayedExchange('default');
        foreach ($queues as $queue) {
            Enforcer::createAndBindQueue('default', $queue);
        }
        Enforcer::destroy('default');
        $GLOBALS['IT']['notes'][] = 'topology declared';
    } catch (\Throwable $e) {
        $GLOBALS['IT']['errors'][] = 'topology: ' . $e->getMessage();
        $finish();
        return;
    }

    // 2. subscribe over STOMP (buffered until the connection is established)
    $process = new ConsumerProcess('');
    $makeCallback = new \ReflectionMethod($process, 'makeCallback');

    $client = Client::connection('default');
    $client->subscribe('it_normal', $makeCallback->invoke($process, new ItNormalConsumer(), 'it_normal', 'client'), [
        'ack' => 'client', 'prefetch-count' => 10,
    ]);
    $client->subscribe('it_delay', $makeCallback->invoke($process, new ItDelayConsumer(), 'it_delay', 'client'), [
        'ack' => 'client', 'prefetch-count' => 10,
    ]);
    $client->subscribe('it_retry', $makeCallback->invoke($process, new ItRetryConsumer(), 'it_retry', 'client'), [
        'ack' => 'client', 'prefetch-count' => 10,
    ]);
    $GLOBALS['IT']['notes'][] = 'subscribed';

    // 3. send after the STOMP connection is established
    $GLOBALS['IT']['send_timer'] = Timer::add(0.05, function () use ($client): void {
        if ($client->getClient()->getState() !== StompClient::STATE_ESTABLISHED) {
            return;
        }
        Timer::del($GLOBALS['IT']['send_timer']);
        $GLOBALS['IT']['delay_sent_at'] = microtime(true);
        $client->send('it_normal', 'normal-body');
        $client->send('it_delay', 'delay-body', 2);
        $client->send('it_retry', 'retry-body');
        $GLOBALS['IT']['notes'][] = 'messages sent';
    }, [], true);

    // 4. wait for all three flows, then keep 1s to catch duplicate deliveries
    Timer::add(0.2, function () use ($finish): void {
        $it = $GLOBALS['IT'];
        if ($it['hits']['it_normal'] < 1 || $it['hits']['it_delay'] < 1 || $it['hits']['it_retry'] < 2) {
            return;
        }
        if ($it['settle_at'] === 0.0) {
            $GLOBALS['IT']['settle_at'] = microtime(true);
            return;
        }
        if (microtime(true) - $it['settle_at'] >= 1.0) {
            $finish();
        }
    });

    Timer::add(30, function () use ($finish): void {
        $GLOBALS['IT']['notes'][] = 'global timeout';
        $finish(true);
    }, [], false);
};

Worker::runAll();
