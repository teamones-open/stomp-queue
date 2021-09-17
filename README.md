# Stomp

Asynchronous STOMP client for [webman](https://github.com/walkor/webman).

# Features

1. Create exchange and queues with php-amqplib/php-amqplib component.
2. Using **/amq/queue/[queuename]** mode to subscribe.
3. Using **/exchange/[exchangename]/[routing_key]** mode to send message.
4. Support delayed message bu **rabbitmq_delayed_message_exchange** plugin.

# Document
https://www.workerman.net/doc/webman#/queue/stomp

# Install

```
composer require teamones/stomp-queue
```

# config

```php
return [
    'default' => [
        'host' => 'stomp://' . env("rabbitmq_host", '127.0.0.1') . ':' . env("rabbitmq_stomp_port", 61613),
        'options' => [
            'vhost' => env("rabbitmq_vhost", '/'),
            'login' => env("rabbitmq_user", 'guest'),
            'passcode' => env("rabbitmq_password", 'guest'),
            'debug' => (bool)env("app_debug", false),
        ],
        'amqp' => [
            'host' => env("rabbitmq_host", '127.0.0.1'),
            'port' => env("rabbitmq_amqp_port", 5672),
            'namespace' => env("belong_system", ''),
            'exchange_name' => env("rabbitmq_exchange_name", 'exchange'),
            'exchange_delay' => (bool)env("rabbitmq_exchange_delay", true)
        ]
    ]
]
```
