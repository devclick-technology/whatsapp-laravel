<?php

return [
    'node_url' => env('WHATSAPP_NODE_URL'),
    'node_token' => env('WHATSAPP_NODE_TOKEN'),
    'app_id' => env('WHATSAPP_APP_ID'),
    'timeout' => (int) env('WHATSAPP_NODE_TIMEOUT', 40),
    'require_https' => env('WHATSAPP_REQUIRE_HTTPS', true),
    'routes_enabled' => true,
    'route_prefix' => 'whatsapp',
    'route_name_prefix' => 'whatsapp.',
    'middleware' => ['web', 'auth'],
    'send_route_enabled' => false,
];
