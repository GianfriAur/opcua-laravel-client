<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | The default OPC UA connection to use when calling Opcua::read(), etc.
    |
    */

    'default' => env('OPCUA_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Session Manager
    |--------------------------------------------------------------------------
    |
    | Configuration for the session manager daemon. The daemon keeps OPC UA
    | connections alive across PHP requests, avoiding reconnection overhead.
    |
    | If the daemon is not running, the client will fall back to direct
    | connections (a new TCP connection per request).
    |
    */

    'session_manager' => [
        'enabled' => env('OPCUA_SESSION_MANAGER_ENABLED', true),
        'socket_path' => env('OPCUA_SOCKET_PATH', storage_path('app/opcua-session-manager.sock')),
        'timeout' => env('OPCUA_SESSION_TIMEOUT', 600),
        'cleanup_interval' => env('OPCUA_CLEANUP_INTERVAL', 30),
        'auth_token' => env('OPCUA_AUTH_TOKEN'),
        'max_sessions' => env('OPCUA_MAX_SESSIONS', 100),
        'socket_mode' => 0600,
        'allowed_cert_dirs' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Define your OPC UA server connections here. Each connection can have
    | its own endpoint, security settings, and credentials.
    |
    */

    'connections' => [

        'default' => [
            'endpoint' => env('OPCUA_ENDPOINT', 'opc.tcp://localhost:4840'),

            // Security (optional)
            'security_policy' => env('OPCUA_SECURITY_POLICY'), // None, Basic128Rsa15, Basic256, Basic256Sha256, Aes128Sha256RsaOaep, Aes256Sha256RsaPss
            'security_mode' => env('OPCUA_SECURITY_MODE'),     // None, Sign, SignAndEncrypt

            // User authentication (optional)
            'username' => env('OPCUA_USERNAME'),
            'password' => env('OPCUA_PASSWORD'),

            // Client certificate (optional)
            'client_certificate' => env('OPCUA_CLIENT_CERT'),
            'client_key' => env('OPCUA_CLIENT_KEY'),
            'ca_certificate' => env('OPCUA_CA_CERT'),

            // User certificate (optional)
            'user_certificate' => env('OPCUA_USER_CERT'),
            'user_key' => env('OPCUA_USER_KEY'),
        ],

    ],

];
