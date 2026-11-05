<?php

return [

    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [

        // Round-robins between providers per message; retries on the other on failure.
        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => ['oci', 'resend'],

            'retry_after' => env('MAIL_ROUNDROBIN_RETRY_AFTER', 60),
        ],

        'oci' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_OCI_SCHEME', 'smtp'),
            'host' => env('MAIL_OCI_HOST'),
            'port' => env('MAIL_OCI_PORT', 587),
            'username' => env('MAIL_OCI_USERNAME'),
            'password' => env('MAIL_OCI_PASSWORD'),
            'timeout' => env('MAIL_SMTP_TIMEOUT', 30),
            'ping_threshold' => env('MAIL_SMTP_PING_THRESHOLD', 10),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'resend' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_RESEND_SCHEME', 'smtp'),
            'host' => env('MAIL_RESEND_HOST', 'smtp.resend.com'),
            'port' => env('MAIL_RESEND_PORT', 587),
            'username' => env('MAIL_RESEND_USERNAME', 'resend'),
            'password' => env('MAIL_RESEND_PASSWORD'),
            'timeout' => env('MAIL_SMTP_TIMEOUT', 30),
            'ping_threshold' => env('MAIL_SMTP_PING_THRESHOLD', 10),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        // Kept for compatibility with environments still on MAIL_MAILER=smtp.
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'ping_threshold' => env('MAIL_SMTP_PING_THRESHOLD', 10),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
