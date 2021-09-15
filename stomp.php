<?php

return [
    'default' => [
        'host' => 'stomp://' . env("amqp_host", '') . ':' . env("amqp_stomp_port", ''),
        'options' => [
            'vhost' => env("amqp_vhost", '/'),
            'login' => env("amqp_user", 'guest'),
            'passcode' => env("amqp_password", 'guest'),
            'debug' => (bool)env("app_debug", false),
        ],
        'amqp' => [
            'host' => env("amqp_host", ''),
            'port' => env("amqp_port", ''),
            'exchange' => env("belong_system", '') . '_delayed_exchange-' . Webpatser\Uuid\Uuid::generate()->string
        ]
    ]
];
