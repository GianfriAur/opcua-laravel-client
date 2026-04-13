# Auto-Publish & Monitoring

## Overview

Auto-publish eliminates the need for manual `publish()` loops when using OPC UA subscriptions with the session manager daemon. When enabled, the daemon automatically calls `publish()` for sessions that have active subscriptions and dispatches PSR-14 events (`DataChangeReceived`, `EventNotificationReceived`, `AlarmActivated`, etc.) through Laravel's event system. Combined with per-connection `auto_connect`, you can define your entire monitoring setup declaratively in `config/opcua.php` — no application code needed.

## How It Works

```
┌──────────────────────────────────────────────────────────────────────────┐
│                         Laravel Application                              │
│                                                                          │
│  EventServiceProvider                                                    │
│    Event::listen(DataChangeReceived::class, ...)                         │
│    Event::listen(AlarmActivated::class, ...)                             │
│                                                                          │
│  config/opcua.php                                                        │
│    auto_publish: true                                                    │
│    connections:                                                          │
│      plc-1: { auto_connect: true, subscriptions: [...] }                 │
│      historian: { }  ← on-demand only                                    │
│                                                                          │
├──────────────────────────────────────────────────────────────────────────┤
│  php artisan opcua:session                                               │
│                                                                          │
│  Daemon (ReactPHP event loop)                                            │
│    ├── Auto-connect: plc-1 → TCP → OPC UA Server                         │
│    ├── Create subscriptions + monitored items                            │
│    ├── AutoPublisher:                                                    │
│    │     ├── Timer → publish() → events dispatched                       │
│    │     ├── Acknowledgements tracked                                    │
│    │     └── Recovery on connection errors                               │
│    └── IPC socket for runtime requests                                   │
│                                                                          │
│  Events dispatched:                                                      │
│    DataChangeReceived → Laravel listener → DB / notification / etc.      │
│    AlarmActivated → Laravel listener → alert operators                   │
│    SubscriptionKeepAlive → (optional logging)                            │
└──────────────────────────────────────────────────────────────────────────┘
```

1. The daemon starts with `auto_publish: true` and a PSR-14 event dispatcher (resolved from Laravel's container)
2. Connections with `auto_connect: true` are established on the first event loop tick
3. Subscriptions and monitored items defined in `subscriptions` are created
4. The daemon's `AutoPublisher` starts a self-rescheduling timer for each session with subscriptions
5. On each publish cycle, the OPC UA client calls `publish()` which internally dispatches PSR-14 events
6. Your Laravel event listeners handle the notifications (store in DB, send alerts, broadcast to frontend, etc.)

## Configuration

### Enable auto-publish

```php
// config/opcua.php
'session_manager' => [
    // ... existing config ...
    'auto_publish' => env('OPCUA_AUTO_PUBLISH', false),
],
```

### Define auto-connect connections

```php
// config/opcua.php
'connections' => [
    'plc-linea-1' => [
        'endpoint' => env('PLC1_ENDPOINT', 'opc.tcp://192.168.1.10:4840'),
        'username' => env('PLC1_USER'),
        'password' => env('PLC1_PASS'),
        'timeout' => 3.0,
        'auto_retry' => 3,

        // Per-connection auto-connect (requires auto_publish to be enabled)
        'auto_connect' => true,

        // Subscriptions to create on daemon startup
        'subscriptions' => [
            [
                'publishing_interval' => 500.0,    // ms
                'max_keep_alive_count' => 5,       // reduces max publish blocking to 2.5s
                // 'lifetime_count' => 2400,
                // 'max_notifications_per_publish' => 0,
                // 'priority' => 0,

                'monitored_items' => [
                    ['node_id' => 'ns=2;s=Temperature',  'client_handle' => 1],
                    ['node_id' => 'ns=2;s=Pressure',     'client_handle' => 2],
                    ['node_id' => 'ns=2;s=MachineState', 'client_handle' => 3],
                    // Optional per-item settings:
                    // 'sampling_interval' => 100.0,
                    // 'queue_size' => 5,
                    // 'attribute_id' => 13,
                ],

                'event_monitored_items' => [
                    [
                        'node_id' => 'i=2253',         // Server object
                        'client_handle' => 10,
                        'select_fields' => [
                            'EventId', 'EventType', 'SourceName', 'Time',
                            'Message', 'Severity',
                            'ActiveState', 'AckedState', 'ConfirmedState',
                        ],
                    ],
                ],
            ],
        ],
    ],

    // Connection without auto_connect — used on-demand from application code
    'historian' => [
        'endpoint' => env('HISTORIAN_ENDPOINT', 'opc.tcp://192.168.1.20:4840'),
    ],
],
```

### Configuration reference

#### Session manager keys

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `auto_publish` | `OPCUA_AUTO_PUBLISH` | `false` | Enable automatic publishing for sessions with subscriptions |

#### Per-connection keys

| Key | Default | Description |
|-----|---------|-------------|
| `auto_connect` | `false` | Auto-connect this endpoint when the daemon starts (requires `auto_publish`) |
| `subscriptions` | *(none)* | Array of subscription definitions with `monitored_items` and `event_monitored_items` |

#### Subscription definition keys

| Key | Default | Description |
|-----|---------|-------------|
| `publishing_interval` | `500.0` | Publishing interval in milliseconds |
| `lifetime_count` | `2400` | Subscription lifetime count |
| `max_keep_alive_count` | `10` | Max keep-alive count (lower = less publish blocking) |
| `max_notifications_per_publish` | `0` | Max notifications per publish (0 = unlimited) |
| `priority` | `0` | Subscription priority |

#### Monitored item keys

| Key | Default | Description |
|-----|---------|-------------|
| `node_id` | *(required)* | Node ID string (`'ns=2;s=Temperature'`, `'i=2259'`) |
| `client_handle` | `0` | Client-assigned handle (returned in notifications) |
| `sampling_interval` | `250.0` | Sampling interval in milliseconds |
| `queue_size` | `1` | Queue size for buffered notifications |
| `attribute_id` | `13` | OPC UA attribute to monitor (13 = Value) |

#### Event monitored item keys

| Key | Default | Description |
|-----|---------|-------------|
| `node_id` | *(required)* | Node ID of the event source (`'i=2253'` for Server object) |
| `client_handle` | `1` | Client-assigned handle |
| `select_fields` | `['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity']` | Event fields to select |

## Event Listeners

Register listeners in your `EventServiceProvider` or via `Event::listen()`:

```php
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\Event\EventNotificationReceived;
use PhpOpcua\Client\Event\AlarmActivated;
use PhpOpcua\Client\Event\AlarmDeactivated;
use PhpOpcua\Client\Event\AlarmAcknowledged;
use PhpOpcua\Client\Event\SubscriptionKeepAlive;

Event::listen(DataChangeReceived::class, function (DataChangeReceived $e) {
    // $e->subscriptionId  — subscription that generated this notification
    // $e->sequenceNumber   — sequence number for acknowledgement tracking
    // $e->clientHandle     — the handle you assigned in monitored_items config
    // $e->dataValue        — DataValue with getValue(), statusCode, sourceTimestamp, serverTimestamp
});

Event::listen(EventNotificationReceived::class, function (EventNotificationReceived $e) {
    // $e->subscriptionId
    // $e->clientHandle
    // $e->eventFields     — Variant[] with values for each select_field
});

Event::listen(AlarmActivated::class, function (AlarmActivated $e) {
    // $e->subscriptionId, $e->clientHandle
    // $e->sourceName, $e->message, $e->severity, $e->eventType, $e->time
});

Event::listen(AlarmDeactivated::class, function (AlarmDeactivated $e) {
    // $e->sourceName, $e->message
});

Event::listen(AlarmAcknowledged::class, function (AlarmAcknowledged $e) {
    // $e->sourceName
});

Event::listen(SubscriptionKeepAlive::class, function (SubscriptionKeepAlive $e) {
    // $e->subscriptionId, $e->sequenceNumber
    // No data — server confirms the subscription is alive
});
```

You can also use dedicated listener classes:

```php
// app/Listeners/StoreSensorReading.php
namespace App\Listeners;

use PhpOpcua\Client\Event\DataChangeReceived;
use Illuminate\Support\Facades\DB;

class StoreSensorReading
{
    public function handle(DataChangeReceived $event): void
    {
        DB::table('sensor_readings')->insert([
            'subscription_id' => $event->subscriptionId,
            'client_handle'   => $event->clientHandle,
            'value'           => $event->dataValue->getValue(),
            'status_code'     => $event->dataValue->statusCode,
            'source_time'     => $event->dataValue->sourceTimestamp,
            'server_time'     => $event->dataValue->serverTimestamp,
            'created_at'      => now(),
        ]);
    }
}
```

```php
// EventServiceProvider
protected $listen = [
    DataChangeReceived::class => [StoreSensorReading::class],
    AlarmActivated::class => [NotifyOperators::class],
];
```

## Runtime Subscriptions

Auto-publish also works for subscriptions created at runtime via the Facade. Any session that gains a subscription is automatically published by the daemon:

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

$client = Opcua::connect('historian');

$sub = $client->createSubscription(publishingInterval: 2000.0);
$client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=4;s=HistorianStatus', 'clientHandle' => 200],
]);

// No publish() loop needed — daemon handles it automatically.
// DataChangeReceived events dispatched to your listeners.
```

## Blocking Behavior

`Client::publish()` is a synchronous call that blocks the daemon's ReactPHP event loop until the OPC UA server responds. The maximum blocking time depends on the subscription's `maxKeepAliveCount × publishingInterval`:

| `max_keep_alive_count` | `publishing_interval` | Max block time |
|------------------------|----------------------|----------------|
| 10 (default) | 500ms | 5.0s |
| 5 | 500ms | 2.5s |
| 3 | 500ms | 1.5s |
| 5 | 250ms | 1.25s |

During the block, incoming IPC requests from your application queue up but are not lost (30s IPC timeout). In practice, when data is changing frequently, `publish()` returns almost immediately (~5ms).

**Recommendation:** use `max_keep_alive_count: 5` or lower to reduce maximum blocking time.

## Error Handling

The `AutoPublisher` handles errors automatically:

| Scenario | Behavior |
|----------|----------|
| Connection lost | Attempts reconnection + subscription transfer. Reschedules after 1s |
| Recovery failed | Auto-publish stops for that session. Logged as error |
| Transient error | Retries with 5s backoff |
| 5 consecutive errors | Auto-publish stops for that session. Logged as error |
| Handler exception | Logged, but auto-publish continues (handler bug should not kill monitoring) |

Connection recovery includes automatic `transferSubscriptions()` and `republish()` for missed notifications.

---

## Real-World Use Case: Industrial Production Line Monitoring

This example demonstrates a complete industrial monitoring setup for a bottling plant with two PLC-controlled production lines and a historian server.

### Scenario

- **Line 1** (PLC at `192.168.1.10:4840`): monitors temperature, pressure, fill level, and machine state. Needs alarms for over-temperature and over-pressure.
- **Line 2** (PLC at `192.168.1.11:4840`): monitors motor speed and vibration. Needs alerts on excessive vibration.
- **Historian** (at `192.168.1.20:4840`): queried on-demand for historical reports — not auto-connected.

### Step 1: Configuration

```dotenv
# .env
OPCUA_AUTO_PUBLISH=true

PLC1_ENDPOINT=opc.tcp://192.168.1.10:4840
PLC1_USER=operator
PLC1_PASS=secret123

PLC2_ENDPOINT=opc.tcp://192.168.1.11:4840

HISTORIAN_ENDPOINT=opc.tcp://192.168.1.20:4840
```

```php
// config/opcua.php
return [
    'default' => 'line-1',

    'session_manager' => [
        'enabled' => true,
        'socket_path' => storage_path('app/opcua-session-manager.sock'),
        'timeout' => 600,
        'auth_token' => env('OPCUA_AUTH_TOKEN'),
        'auto_publish' => env('OPCUA_AUTO_PUBLISH', false),
    ],

    'connections' => [

        'line-1' => [
            'endpoint' => env('PLC1_ENDPOINT'),
            'username' => env('PLC1_USER'),
            'password' => env('PLC1_PASS'),
            'timeout' => 3.0,
            'auto_retry' => 3,
            'auto_connect' => true,

            'subscriptions' => [
                [
                    'publishing_interval' => 500.0,
                    'max_keep_alive_count' => 5,

                    'monitored_items' => [
                        ['node_id' => 'ns=2;s=Line1.Temperature',  'client_handle' => 1],
                        ['node_id' => 'ns=2;s=Line1.Pressure',     'client_handle' => 2],
                        ['node_id' => 'ns=2;s=Line1.FillLevel',    'client_handle' => 3],
                        ['node_id' => 'ns=2;s=Line1.MachineState', 'client_handle' => 4],
                    ],

                    'event_monitored_items' => [
                        [
                            'node_id' => 'i=2253',
                            'client_handle' => 100,
                            'select_fields' => [
                                'EventId', 'EventType', 'SourceName', 'Time',
                                'Message', 'Severity', 'ActiveState',
                                'AckedState', 'ConfirmedState',
                            ],
                        ],
                    ],
                ],
            ],
        ],

        'line-2' => [
            'endpoint' => env('PLC2_ENDPOINT'),
            'timeout' => 3.0,
            'auto_retry' => 3,
            'auto_connect' => true,

            'subscriptions' => [
                [
                    'publishing_interval' => 250.0,
                    'max_keep_alive_count' => 3,

                    'monitored_items' => [
                        ['node_id' => 'ns=3;s=Line2.MotorSpeed',   'client_handle' => 10, 'sampling_interval' => 100.0],
                        ['node_id' => 'ns=3;s=Line2.Vibration',    'client_handle' => 11, 'sampling_interval' => 100.0],
                    ],
                ],
            ],
        ],

        'historian' => [
            'endpoint' => env('HISTORIAN_ENDPOINT'),
            'timeout' => 10.0,
        ],

    ],
];
```

### Step 2: Database Migration

```php
// database/migrations/2026_04_08_create_sensor_readings_table.php
Schema::create('sensor_readings', function (Blueprint $table) {
    $table->id();
    $table->integer('subscription_id');
    $table->integer('client_handle');
    $table->string('connection');
    $table->float('value')->nullable();
    $table->integer('status_code');
    $table->timestamp('source_time')->nullable();
    $table->timestamp('server_time')->nullable();
    $table->timestamps();

    $table->index(['connection', 'client_handle', 'created_at']);
});

Schema::create('alarm_log', function (Blueprint $table) {
    $table->id();
    $table->string('source');
    $table->string('message');
    $table->integer('severity');
    $table->string('state');
    $table->timestamp('event_time')->nullable();
    $table->timestamps();
});
```

### Step 3: Event Listeners

```php
// app/Listeners/StoreSensorReading.php
namespace App\Listeners;

use PhpOpcua\Client\Event\DataChangeReceived;
use Illuminate\Support\Facades\DB;

class StoreSensorReading
{
    private const HANDLE_TO_CONNECTION = [
        1 => 'line-1', 2 => 'line-1', 3 => 'line-1', 4 => 'line-1',
        10 => 'line-2', 11 => 'line-2',
    ];

    public function handle(DataChangeReceived $event): void
    {
        DB::table('sensor_readings')->insert([
            'subscription_id' => $event->subscriptionId,
            'client_handle'   => $event->clientHandle,
            'connection'      => self::HANDLE_TO_CONNECTION[$event->clientHandle] ?? 'unknown',
            'value'           => $event->dataValue->getValue(),
            'status_code'     => $event->dataValue->statusCode,
            'source_time'     => $event->dataValue->sourceTimestamp,
            'server_time'     => $event->dataValue->serverTimestamp,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }
}
```

```php
// app/Listeners/HandleAlarm.php
namespace App\Listeners;

use PhpOpcua\Client\Event\AlarmActivated;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Notifications\OpcUaAlarmNotification;
use App\Models\User;

class HandleAlarm
{
    public function handle(AlarmActivated $event): void
    {
        DB::table('alarm_log')->insert([
            'source'     => $event->sourceName,
            'message'    => $event->message,
            'severity'   => $event->severity,
            'state'      => 'active',
            'event_time' => $event->time,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::channel('opcua')->warning('OPC UA Alarm: {source} — {message}', [
            'source'   => $event->sourceName,
            'message'  => $event->message,
            'severity' => $event->severity,
        ]);

        if ($event->severity >= 800) {
            $operators = User::role('operator')->get();
            Notification::send($operators, new OpcUaAlarmNotification(
                source: $event->sourceName,
                message: $event->message,
                severity: $event->severity,
            ));
        }
    }
}
```

```php
// app/Listeners/DetectVibrationAnomaly.php
namespace App\Listeners;

use PhpOpcua\Client\Event\DataChangeReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DetectVibrationAnomaly
{
    private const VIBRATION_HANDLE = 11;
    private const THRESHOLD = 5.0;

    public function handle(DataChangeReceived $event): void
    {
        if ($event->clientHandle !== self::VIBRATION_HANDLE) {
            return;
        }

        $vibration = $event->dataValue->getValue();

        if ($vibration > self::THRESHOLD) {
            $key = "vibration_alert_{$event->clientHandle}";
            if (!Cache::has($key)) {
                Cache::put($key, true, 300);
                Log::channel('opcua')->alert('High vibration detected on Line 2: {value} mm/s', [
                    'value' => $vibration,
                ]);
            }
        }
    }
}
```

### Step 4: Register Listeners

```php
// app/Providers/EventServiceProvider.php
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\Event\AlarmActivated;

protected $listen = [
    DataChangeReceived::class => [
        \App\Listeners\StoreSensorReading::class,
        \App\Listeners\DetectVibrationAnomaly::class,
    ],
    AlarmActivated::class => [
        \App\Listeners\HandleAlarm::class,
    ],
];
```

### Step 5: Start

```bash
php artisan opcua:session
```

Output:
```
Starting OPC UA Session Manager...
+---------------------+----------------------------------------------+
| Setting             | Value                                        |
+---------------------+----------------------------------------------+
| Socket              | storage/app/opcua-session-manager.sock       |
| Timeout             | 600s                                         |
| Auth Token          | configured                                   |
| Auto-publish        | enabled                                      |
+---------------------+----------------------------------------------+
Auto-connecting 2 connection(s): line-1, line-2
Auto-connected "line-1" (session: a1b2c3d4...)
Auto-connected "line-2" (session: e5f6g7h8...)
Auto-publish enabled
OPC UA Session Manager started on storage/app/opcua-session-manager.sock
```

The daemon:
1. Connects to both PLCs with the configured credentials
2. Creates subscriptions with the specified publishing intervals
3. Registers all monitored items and event monitors
4. Auto-publishes and dispatches events to your Laravel listeners
5. `StoreSensorReading` stores every data change to the database
6. `HandleAlarm` logs alarms and sends notifications for critical ones (severity >= 800)
7. `DetectVibrationAnomaly` watches vibration levels and alerts on threshold breach

The `historian` connection is not auto-connected — it can be used from a controller or job whenever historical data is needed:

```php
// In a controller — on-demand historian query
$client = Opcua::connect('historian');
$history = $client->historyReadRaw(
    'ns=4;s=Line1.Temperature',
    new \DateTimeImmutable('-1 hour'),
    new \DateTimeImmutable('now'),
    numValuesPerNode: 1000,
);
$client->disconnect();
```

### Step 6: Production Deployment

```ini
; /etc/supervisor/conf.d/opcua-session.conf
[program:opcua-session]
command=php /var/www/app/artisan opcua:session
directory=/var/www/app
user=www-data
autostart=true
autorestart=true
environment=OPCUA_AUTH_TOKEN="%(ENV_OPCUA_AUTH_TOKEN)s"
redirect_stderr=true
stdout_logfile=/var/log/opcua-session.log
```
