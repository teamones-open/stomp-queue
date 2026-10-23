# Stomp

Asynchronous STOMP client for [webman](https://github.com/walkor/webman), with AMQP topology declaration.

## Requirements

- PHP >= 8.1 (CI covers 8.1, 8.2, 8.3, 8.4 and 8.5)
- `ext-sockets` and `ext-mbstring` (required by `php-amqplib/php-amqplib`)
- RabbitMQ with the STOMP plugin enabled (default port `61613`)
- Optional: `rabbitmq_delayed_message_exchange` for delayed messages

## Features

1. Create exchanges and queues with `php-amqplib/php-amqplib`.
2. Subscribe in `/amq/queue/[queue_name]` mode.
3. Send in `/exchange/[exchange_name]/[routing_key]` mode.
4. Delayed messages through the `rabbitmq_delayed_message_exchange` plugin.

## Document

https://www.workerman.net/doc/webman#/queue/stomp

## Install

```
composer require teamones/stomp-queue:^3.0
```

## Config

`config/stomp.php`:

```php
return [
    'default' => [
        'host' => 'stomp://' . env("rabbitmq_host", '127.0.0.1') . ':' . env("rabbitmq_stomp_port", 61613),
        // optional: broker ERROR reconnect budget per 60s (default 3)
        // 'max_reconnects' => 3,
        'options' => [
            'vhost' => env("rabbitmq_vhost", '/'),
            'login' => env("rabbitmq_user", 'guest'),
            'passcode' => env("rabbitmq_password", 'guest'),
            'debug' => (bool)env("app_debug", false),
            // heartbeat in milliseconds, [send, receive]
            'heart_beat' => [10000, 10000],
        ],
        'amqp' => [
            'host' => env("rabbitmq_host", '127.0.0.1'),
            'port' => env("rabbitmq_amqp_port", 5672),
            // prefix of exchange, queue and routing key
            'namespace' => env("belong_system", ''),
            'exchange_name' => env("rabbitmq_exchange_name", 'exchange'),
            'exchange_delay' => (bool)env("rabbitmq_exchange_delay", true),
            // optional, defaults to the STOMP credentials
            // 'login' => '', 'passcode' => '', 'vhost' => '/',
            // optional AMQP timeouts
            // 'connection_timeout' => 3.0, 'read_write_timeout' => 3.0, 'heartbeat' => 0,
        ],
    ],
];
```

## Produce

```php
use Webman\Stomp\Client;

Client::send('send_message', $json, 0);          // immediate
Client::send('send_message', $json, 10);         // delay 10s, needs exchange_delay
Client::connection('other')->send('send_message', $json);
```

The body must already be serialized (string). A delay without
`amqp.exchange_delay = true` throws a `RuntimeException` instead of silently
delivering the message immediately.

## Consume

```php
use Webman\Stomp\Consumer;
use Webman\Stomp\RetryAbleConsumer;
use Workerman\Stomp\AckResolver;

class SendSmsConsumer implements Consumer, RetryAbleConsumer
{
    public $queue = 'send_sms';
    public $connection = 'default';
    public $ack = 'client';
    public $prefetch = 10; // optional, defaults to 10

    public function consume($data, ?AckResolver $ackResolver = null)
    {
        // ...
        $ackResolver->ack();
    }

    public function getTtr() { return 30; }

    public function canRetry($attempt, $error) { return $attempt < 3; }
}
```

Register the process in `config/process.php`:

```php
'stomp_consumer' => [
    'handler' => Webman\Stomp\Process\Consumer::class,
    'count' => 2,
    'constructor' => [
        'consumer_dir' => app_path() . '/queue/stomp'
    ]
],
```

`$queue` may also be an array to consume several queues with one class.

## Behaviour notes (3.x)

- Each connection name owns its own AMQP connection/channel; two connections can
  be declared in the same worker without overwriting each other.
- `subscribe()` strips the internal `short_name` header from the STOMP frame and
  builds the destination exactly once. `subscribeWithAck()` no longer produces
  `/amq/queue/ns./amq/queue/ns.queue`.
- Calls made while the connection is down are buffered (max 10000) and flushed on
  connect **and** on reconnect. Subscriptions already known to workerman are not
  registered twice.
- A consumer that does not implement `RetryAbleConsumer` still rethrows, keeping
  at-least-once delivery, but the error is logged with its queue and class.
- Broker `ERROR` frames (for example `Server cancelled subscription` after a queue
  is deleted) are logged; bursts within 2 seconds are coalesced into one reconnect,
  and the client reconnects at most 3 times per 60 seconds (override with the
  optional `max_reconnects` key) so workerman can restore the subscriptions.
  TCP-level failures keep using workerman's own reconnect logic.
- During `reload`/`shutdown` the consumer callback stops taking messages and leaves
  them unacknowledged, so the broker redelivers them instead of losing them.
- Failures are logged through `support\Log` / `support\bootstrap\Log` when
  available, otherwise through `error_log`.

## Upgrade from 0.x

The public API (`Client::send`, `Client::connection`, `Consumer`,
`RetryAbleConsumer`, config layout) is unchanged. Behaviour changes are listed
above; the notable one is that a delayed send without a delayed exchange now
throws instead of silently delivering immediately.

## Testing

```
composer test
```

The suite is dependency-free: it stubs the workerman client and converts PHP
warnings/deprecations into failures, so PHP 8.5 deprecations are caught without a
broker.

Integration test against a real RabbitMQ (STOMP + delayed message plugin):

```
docker compose -f tests/docker-compose.yml up -d --wait
STOMP_HOST=127.0.0.1 STOMP_PORT=61613 AMQP_HOST=127.0.0.1 AMQP_PORT=5672 \
RABBITMQ_USER=stomp RABBITMQ_PASS=stomp composer test:integration
docker compose -f tests/docker-compose.yml down -v
```

The compose file creates a disposable broker with the `stomp` user (the default
`guest` user is loopback-only). Override `RABBITMQ_AMQP_PORT` /
`RABBITMQ_STOMP_PORT` if the default ports are already in use.

It declares a delayed exchange and three queues in a unique namespace, then checks
normal delivery, a 2 second delay, retry-then-success, manual ack and cleanup.
CI runs it on every push (see `.github/workflows/ci.yml`).
