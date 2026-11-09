<?php

return [
    /*
    | Master switch for remote control. When false, the pages, the registration
    | API and the websocket server are disabled, and related UI is hidden.
    | Existing registrations are kept, so turning it back on restores them.
    */
    'enabled' => (bool) env('REMOTE_CONTROL_ENABLED', true),

    'bridge_url' => env('REMOTE_CONTROL_BRIDGE_URL'),
    'websocket_path' => env('REMOTE_CONTROL_WEBSOCKET_PATH', '/api/remote-control/ws'),
    'certificates_path' => env('REMOTE_CONTROL_CERTIFICATES_PATH', '/api/remote-control/certificates'),
    'ws_host' => env('REMOTE_CONTROL_WS_HOST', '0.0.0.0'),
    'ws_port' => env('REMOTE_CONTROL_WS_PORT', 8090),
    'server_version' => env('REMOTE_CONTROL_SERVER_VERSION', '1.0.0'),
];

