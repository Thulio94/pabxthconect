<?php

return [
    'runtime_path' => env('PBX_RUNTIME_PATH', storage_path('app/pbx-runtime')),
    'public_media_address' => env('PBX_PUBLIC_IP'),
    'sip_domain' => env('PBX_SIP_DOMAIN', 'localhost'),
    'websocket_url' => env('PBX_WEBSOCKET_URL', 'ws://localhost:8088/asterisk/ws'),
    'turn' => [
        // Coturn and Laravel share this secret. Browsers receive only a
        // temporary REST credential generated for the authenticated extension.
        'host' => env('TURN_PUBLIC_HOST'),
        'realm' => env('TURN_REALM'),
        'auth_secret' => env('TURN_AUTH_SECRET'),
        'ttl_seconds' => max(60, min(3600, (int) env('TURN_CREDENTIAL_TTL_SECONDS', 900))),
        'tls_enabled' => filter_var(env('TURN_TLS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'port' => (int) env('TURN_PORT', 3478),
        'tls_port' => (int) env('TURN_TLS_PORT', 5349),
    ],
    'ami' => [
        'host' => env('PBX_AMI_HOST', 'asterisk'),
        'port' => (int) env('PBX_AMI_PORT', 5038),
        'username' => env('PBX_AMI_USERNAME'),
        'secret' => env('PBX_AMI_SECRET'),
        'timeout' => (int) env('PBX_AMI_TIMEOUT', 3),
    ],
    'call_state' => [
        'ringing_timeout_seconds' => min(40, (int) env('PBX_RINGING_TIMEOUT_SECONDS', 40)),
        'answered_check_after_seconds' => (int) env('PBX_ANSWERED_CHECK_AFTER_SECONDS', 90),
    ],
];
