<?php

return [
    'default' => [
        'host' => 'stomp://' . env("rabbitmq_host", '127.0.0.1') . ':' . env("rabbitmq_stomp_port", 61613),
        'options' => [
            'vhost' => env("rabbitmq_vhost", '/'),
            'login' => env("rabbitmq_user", 'guest'),
            'passcode' => env("rabbitmq_password", 'guest'),
            'debug' => (bool)env("app_debug", false),
            'heart_beat' => [10000, 10000]
        ],
        'amqp' => [
            'host' => env("rabbitmq_host", '127.0.0.1'),
            'port' => env("rabbitmq_amqp_port", 5672),
            'namespace' => env("belong_system", ''),
            'exchange_name' => env("rabbitmq_exchange_name", 'exchange'),
            'exchange_delay' => (bool)env("rabbitmq_exchange_delay", true)
        ]
    ]
];
