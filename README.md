# OPC UA Laravel Client

[![Tests](https://github.com/GianfriAur/opcua-laravel-client/actions/workflows/tests.yml/badge.svg)](https://github.com/GianfriAur/opcua-laravel-client/actions/workflows/tests.yml)
[![Latest Stable Version](https://poser.pugx.org/gianfriaur/opcua-laravel-client/v/stable)](https://packagist.org/packages/gianfriaur/opcua-laravel-client)
[![License](https://poser.pugx.org/gianfriaur/opcua-laravel-client/license)](https://packagist.org/packages/gianfriaur/opcua-laravel-client)

A first-party Laravel integration for [OPC UA](https://opcfoundation.org/about/opc-technologies/opc-ua/) built on top of [`gianfriaur/opcua-php-client`](https://packagist.org/packages/gianfriaur/opcua-php-client) and [`gianfriaur/opcua-php-client-session-manager`](https://packagist.org/packages/gianfriaur/opcua-php-client-session-manager).

This package brings OPC UA communication into the Laravel ecosystem with a familiar developer experience: a `Facade`, environment-based configuration, named connections (like `config/database.php`), and an Artisan command for the optional session manager daemon.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Basic Setup](#basic-setup)
  - [Authentication](#authentication)
  - [Security](#security)
  - [Session Manager](#session-manager)
  - [Multiple Connections](#multiple-connections)
- [Usage](#usage)
  - [Reading Values](#reading-values)
  - [Writing Values](#writing-values)
  - [Browsing the Address Space](#browsing-the-address-space)
  - [Recursive Browsing](#recursive-browsing)
  - [Path Resolution](#path-resolution)
  - [Reading Multiple Values](#reading-multiple-values)
  - [Connection State and Reconnect](#connection-state-and-reconnect)
  - [Timeout and Auto-Retry](#timeout-and-auto-retry)
  - [Calling Methods](#calling-methods)
  - [Subscriptions and Monitored Items](#subscriptions-and-monitored-items)
  - [Historical Data Access](#historical-data-access)
  - [Switching Connections](#switching-connections)
  - [Ad-hoc Connections](#ad-hoc-connections)
  - [Dependency Injection](#dependency-injection)
- [Session Manager](#session-manager-1)
  - [Overview](#overview)
  - [Starting the Daemon](#starting-the-daemon)
  - [Command Options](#command-options)
  - [Production Deployment](#production-deployment)
  - [Architecture](#architecture)
- [Testing](#testing)
- [Ecosystem](#ecosystem)
- [License](#license)

## Features

- **Facade** &mdash; access OPC UA through `Opcua::` with full IDE autocompletion
- **Named connections** &mdash; define multiple OPC UA servers and switch between them, just like database connections
- **Ad-hoc connections** &mdash; connect to any endpoint at runtime with `Opcua::connectTo()`, no prior configuration required
- **Transparent session management** &mdash; when the session manager daemon is running, connections are automatically persisted across HTTP requests; when it is not, the package falls back to direct per-request connections with zero code changes
- **Artisan integration** &mdash; start the session manager daemon with `php artisan opcua:session`
- **Environment-driven configuration** &mdash; endpoints, security policies, credentials and certificates are all configurable via `.env`
- **Configurable timeout** &mdash; per-connection I/O timeout via config or fluent API
- **Auto-retry** &mdash; automatic reconnection and retry on connection failures
- **Transparent batching** &mdash; `readMulti`/`writeMulti` calls are automatically split when exceeding server limits
- **Recursive browsing** &mdash; `browseAll()`, `browseRecursive()` with configurable depth and cycle detection
- **Path resolution** &mdash; `resolveNodeId('/Objects/Server/ServerStatus')` for human-readable path navigation
- **Connection state tracking** &mdash; `isConnected()`, `getConnectionState()`, `reconnect()`
- **Laravel 11 and 12** compatibility

## Requirements

| Dependency | Version |
|---|---|
| PHP | >= 8.2 |
| ext-openssl | * |
| Laravel | 11.x or 12.x |

## Installation

```bash
composer require gianfriaur/opcua-laravel-client
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=opcua-config
```

This will create `config/opcua.php` in your application.

## Configuration

### Basic Setup

Add the OPC UA server endpoint to your `.env` file:

```dotenv
OPCUA_ENDPOINT=opc.tcp://192.168.1.100:4840
```

That is all you need to get started.

### Authentication

To authenticate with username and password:

```dotenv
OPCUA_USERNAME=admin
OPCUA_PASSWORD=secret
```

### Security

To enable encrypted and/or signed communication:

```dotenv
OPCUA_SECURITY_POLICY=Basic256Sha256
OPCUA_SECURITY_MODE=SignAndEncrypt
OPCUA_CLIENT_CERT=/path/to/client.pem
OPCUA_CLIENT_KEY=/path/to/client.key
OPCUA_CA_CERT=/path/to/ca.pem
```

**Supported security policies:** `None`, `Basic128Rsa15`, `Basic256`, `Basic256Sha256`, `Aes128Sha256RsaOaep`, `Aes256Sha256RsaPss`

**Supported security modes:** `None`, `Sign`, `SignAndEncrypt`

### Client Behaviour (v2.0)

Optional per-connection settings for timeout, retry and batching behaviour:

```dotenv
OPCUA_TIMEOUT=10.0
OPCUA_AUTO_RETRY=3
OPCUA_BATCH_SIZE=100
OPCUA_BROWSE_MAX_DEPTH=20
```

These can also be set per-connection in `config/opcua.php` or via the fluent API on the client instance.

### Session Manager

The session manager daemon keeps OPC UA TCP connections alive across PHP requests. It is entirely optional; see [Session Manager](#session-manager-1) for details.

```dotenv
OPCUA_SESSION_MANAGER_ENABLED=true
OPCUA_SOCKET_PATH=                    # defaults to storage/app/opcua-session-manager.sock
OPCUA_SESSION_TIMEOUT=600
OPCUA_CLEANUP_INTERVAL=30
OPCUA_AUTH_TOKEN=my-secret-token
OPCUA_MAX_SESSIONS=100
```

### Multiple Connections

Define additional connections in `config/opcua.php`, following the same pattern as Laravel's `config/database.php`:

```php
'connections' => [

    'default' => [
        'endpoint' => env('OPCUA_ENDPOINT', 'opc.tcp://localhost:4840'),
        'username' => env('OPCUA_USERNAME'),
        'password' => env('OPCUA_PASSWORD'),
    ],

    'plc-line-1' => [
        'endpoint' => 'opc.tcp://10.0.0.10:4840',
        'username' => 'operator',
        'password' => 'pass123',
    ],

    'plc-line-2' => [
        'endpoint' => 'opc.tcp://10.0.0.11:4840',
        'security_policy' => 'Basic256Sha256',
        'security_mode' => 'SignAndEncrypt',
        'client_certificate' => '/etc/opcua/certs/client.pem',
        'client_key' => '/etc/opcua/certs/client.key',
    ],

],
```

Set the default connection name:

```dotenv
OPCUA_CONNECTION=plc-line-1
```

## Usage

### Reading Values

```php
use Gianfriaur\OpcuaLaravel\Facades\Opcua;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

$client = Opcua::connect();

$value = $client->read(NodeId::numeric(2, 1001));
echo $value->getValue(); // e.g. 42

$client->disconnect();
```

### Writing Values

```php
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

$client = Opcua::connect();

$status = $client->write(
    NodeId::numeric(2, 1001),
    100,
    BuiltinType::Int32,
);

if (StatusCode::isGood($status)) {
    echo 'Write successful';
}

$client->disconnect();
```

### Browsing the Address Space

```php
$client = Opcua::connect();

// Browse the Objects folder (ns=0, i=85)
$references = $client->browse(NodeId::numeric(0, 85));

foreach ($references as $ref) {
    echo $ref->getDisplayName() . ' (' . $ref->getNodeClass()->name . ')' . PHP_EOL;
}

$client->disconnect();
```

### Recursive Browsing

```php
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;

$client = Opcua::connect();

$allRefs = $client->browseAll(NodeId::numeric(0, 85));

$tree = $client->browseRecursive(NodeId::numeric(0, 85), maxDepth: 3);

foreach ($tree as $node) {
    echo $node->getDisplayName() . PHP_EOL;
    foreach ($node->getChildren() as $child) {
        echo '  ' . $child->getDisplayName() . PHP_EOL;
    }
}

$client->disconnect();
```

### Path Resolution

```php
$client = Opcua::connect();

$nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus');
$dv = $client->read($nodeId);

use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;

$results = $client->translateBrowsePaths([
    [
        'startingNodeId' => NodeId::numeric(0, 84),
        'relativePath' => [
            ['targetName' => new QualifiedName(0, 'Objects')],
            ['targetName' => new QualifiedName(0, 'Server')],
        ],
    ],
]);

$client->disconnect();
```

### Reading Multiple Values

```php
$client = Opcua::connect();

$values = $client->readMulti([
    ['nodeId' => NodeId::numeric(2, 1001)],
    ['nodeId' => NodeId::numeric(2, 1002)],
    ['nodeId' => NodeId::numeric(2, 1003)],
]);

foreach ($values as $dataValue) {
    echo $dataValue->getValue() . PHP_EOL;
}

$client->disconnect();
```

### Connection State and Reconnect

```php
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;

$client = Opcua::connect();

echo $client->isConnected();           // true
echo $client->getConnectionState();    // ConnectionState::Connected

// Reconnect (e.g. after a network interruption)
$client->reconnect();

$client->disconnect();
echo $client->getConnectionState();    // ConnectionState::Disconnected
```

### Timeout and Auto-Retry

```php
// Via config (config/opcua.php)
// 'timeout' => 10.0,      // seconds
// 'auto_retry' => 3,      // max retries on ConnectionException
// 'batch_size' => 100,     // items per readMulti/writeMulti batch

// Or via fluent API
$client = Opcua::connection();
$client->setTimeout(10.0)
    ->setAutoRetry(3)
    ->setBatchSize(100)
    ->connect('opc.tcp://...');
```

### Calling Methods

```php
use Gianfriaur\OpcuaPhpClient\Types\Variant;

$client = Opcua::connect();

$result = $client->call(
    NodeId::numeric(0, 85),   // parent object
    NodeId::numeric(2, 5000), // method node
    [
        new Variant(BuiltinType::Double, 3.0),
        new Variant(BuiltinType::Double, 4.0),
    ],
);

if (StatusCode::isGood($result['statusCode'])) {
    // $result['outputArguments'] contains the return values
}

$client->disconnect();
```

### Subscriptions and Monitored Items

```php
$client = Opcua::connect();

// Create a subscription
$sub = $client->createSubscription(publishingInterval: 500.0);

// Monitor a node for data changes
$monitored = $client->createMonitoredItems($sub['subscriptionId'], [
    ['nodeId' => NodeId::numeric(2, 1001), 'samplingInterval' => 250.0],
]);

// Poll for notifications
$notification = $client->publish();

// Clean up
$client->deleteSubscription($sub['subscriptionId']);
$client->disconnect();
```

### Historical Data Access

```php
$client = Opcua::connect();

$history = $client->historyReadRaw(
    NodeId::numeric(2, 1001),
    new \DateTimeImmutable('-1 hour'),
    new \DateTimeImmutable('now'),
);

foreach ($history as $dataValue) {
    echo $dataValue->getSourceTimestamp()->format('H:i:s')
        . ' => ' . $dataValue->getValue() . PHP_EOL;
}

$client->disconnect();
```

### Switching Connections

```php
// Connect using a named connection
$client = Opcua::connect('plc-line-1');
$value = $client->read(NodeId::numeric(2, 1001));
$client->disconnect();

// Retrieve a connection instance without connecting
$client = Opcua::connection('plc-line-2');
$client->connect('opc.tcp://10.0.0.11:4840');
// ...
$client->disconnect();

// Disconnect all open connections
Opcua::disconnectAll();
```

### Ad-hoc Connections

Connect to any OPC UA server at runtime without defining it in `config/opcua.php`:

```php
// Minimal usage
$client = Opcua::connectTo('opc.tcp://192.168.1.50:4840');
$value = $client->read(NodeId::numeric(2, 1001));
$client->disconnect();
```

Pass inline configuration using the same keys as a connection entry:

```php
$client = Opcua::connectTo('opc.tcp://10.0.0.99:4840', [
    'username' => 'operator',
    'password' => 'secret',
    'security_policy' => 'Basic256Sha256',
    'security_mode' => 'SignAndEncrypt',
    'client_certificate' => '/etc/opcua/certs/client.pem',
    'client_key' => '/etc/opcua/certs/client.key',
]);

$value = $client->read(NodeId::numeric(2, 2000));
$client->disconnect();
```

Assign a name to retrieve or disconnect the connection later:

```php
$client = Opcua::connectTo('opc.tcp://10.0.0.99:4840', as: 'temp-plc');

// Retrieve by name
$same = Opcua::connection('temp-plc');

// Disconnect by name
Opcua::disconnect('temp-plc');
```

Ad-hoc connections are also cleaned up by `Opcua::disconnectAll()`.

### Dependency Injection

The `OpcuaManager` can be resolved from the service container:

```php
use Gianfriaur\OpcuaLaravel\OpcuaManager;

class PlcController extends Controller
{
    public function read(OpcuaManager $opcua)
    {
        $client = $opcua->connect();
        $value = $client->read(NodeId::numeric(2, 1001));
        $client->disconnect();

        return response()->json(['value' => $value->getValue()]);
    }
}
```

### Checking Session Manager Status

```php
if (Opcua::isSessionManagerRunning()) {
    // Persistent connections via daemon
} else {
    // Direct per-request connections
}
```

## Session Manager

### Overview

PHP's request/response lifecycle creates a new process (or thread) for every HTTP request. Without the session manager, each request must establish a full OPC UA TCP connection and session handshake, which typically adds 50-200ms of overhead.

The session manager solves this by running a long-lived daemon process that maintains persistent OPC UA connections. PHP requests communicate with the daemon through a lightweight Unix socket, eliminating the per-request connection cost.

**The session manager is entirely optional.** If the daemon is not running, the package transparently falls back to direct connections. No code changes are required to switch between the two modes.

### Starting the Daemon

```bash
php artisan opcua:session
```

The daemon creates a Unix socket at `storage/app/opcua-session-manager.sock`. When the `Opcua` Facade creates a new connection, it checks for the socket and automatically routes traffic through the daemon if available.

### Command Options

```bash
php artisan opcua:session \
    --timeout=600 \
    --cleanup-interval=30 \
    --max-sessions=100 \
    --socket-mode=0600
```

| Option | Description | Default |
|---|---|---|
| `--timeout` | Session inactivity timeout in seconds | `600` |
| `--cleanup-interval` | Interval between expired session checks | `30` |
| `--max-sessions` | Maximum number of concurrent OPC UA sessions | `100` |
| `--socket-mode` | Unix socket file permissions (octal) | `0600` |

All options can also be configured via `.env` or `config/opcua.php`.

### Production Deployment

Use a process manager such as Supervisor to keep the daemon running:

```ini
[program:opcua-session-manager]
command=php /path/to/artisan opcua:session
directory=/path/to/laravel
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/laravel/storage/logs/opcua-session-manager.log
```

### Architecture

```
HTTP Request
    |
    v
Opcua::connect()
    |
    +--- socket exists? ---> YES ---> ManagedClient (Unix socket IPC to daemon)
    |                                       |
    +--- socket missing? --> NO  ---> Direct Client (new TCP connection per request)
                                            |
                                            v
                                    OPC UA Server
```

The daemon maintains persistent TCP connections to OPC UA servers and multiplexes PHP requests over them via Unix socket IPC with JSON-encoded messages. Idle sessions are automatically cleaned up after the configured timeout.

## Testing

Run the unit tests (no external dependencies):

```bash
vendor/bin/pest tests/Unit
```

Run the integration tests (requires the [opcua-test-server-suite](https://github.com/GianfriAur/opcua-test-server-suite) Docker containers):

```bash
vendor/bin/pest --group=integration
```

## Ecosystem

This package is part of a broader OPC UA ecosystem for PHP:

| Package | Description |
|---------|-------------|
| [opcua-php-client](https://github.com/GianfriAur/opcua-php-client) | Pure PHP OPC UA client library |
| [opcua-php-client-session-manager](https://github.com/GianfriAur/opcua-php-client-session-manager) | Session persistence and management across PHP requests, bridging OPC UA's long-lived sessions with PHP's short-lived request model |
| [opcua-laravel-client](https://github.com/GianfriAur/opcua-laravel-client) | Laravel integration for OPC UA (this package) |
| [opcua-test-server-suite](https://github.com/GianfriAur/opcua-test-server-suite) | Docker-based OPC UA test server suite with multiple security configurations, custom data types, and a comprehensive address space for integration testing |

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
