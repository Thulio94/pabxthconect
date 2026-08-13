<?php

return [
    'runtime_path' => env('PBX_RUNTIME_PATH', storage_path('app/pbx-runtime')),
    'sip_domain' => env('PBX_SIP_DOMAIN', 'localhost'),
    'websocket_url' => env('PBX_WEBSOCKET_URL', 'ws://localhost:8088/asterisk/ws'),
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
