# Events

## Overview

The OPC UA client dispatches **47 PSR-14 events** covering every stage of the client lifecycle: connections, reads, writes, browses, subscriptions, alarms, cache operations, retries, security, and type discovery. In Laravel, these events flow through Laravel's event system automatically — just register listeners.

## How It Works

The `OpcuaServiceProvider` resolves `Psr\EventDispatcher\EventDispatcherInterface` from the Laravel container and passes it to `OpcuaManager`. Every client created through the manager dispatches events through this dispatcher. No extra configuration needed.

```
OPC UA Client ──dispatch──▸ PSR-14 EventDispatcher ──▸ Laravel Event System ──▸ Your Listeners
```

## Listening to Events

### Using Event::listen

```php
// AppServiceProvider or any service provider boot() method

use Illuminate\Support\Facades\Event;
use PhpOpcua\Client\Event\ClientConnected;
use PhpOpcua\Client\Event\NodeValueRead;
use PhpOpcua\Client\Event\DataChangeReceived;

Event::listen(ClientConnected::class, function (ClientConnected $e) {
    logger()->info("Connected to {$e->endpointUrl}");
});

Event::listen(NodeValueRead::class, function (NodeValueRead $e) {
    logger()->debug("Read {$e->nodeId}: {$e->dataValue->getValue()}");
});

Event::listen(DataChangeReceived::class, function (DataChangeReceived $e) {
    DB::table('sensor_readings')->insert([
        'value' => $e->dataValue->getValue(),
        'client_handle' => $e->clientHandle,
        'timestamp' => now(),
    ]);
});
```

### Using Listener Classes

```php
// app/Listeners/LogOpcUaConnection.php
namespace App\Listeners;

use PhpOpcua\Client\Event\ClientConnected;

class LogOpcUaConnection
{
    public function handle(ClientConnected $event): void
    {
        logger()->info("OPC UA connected to {$event->endpointUrl}");
    }
}
```

```php
// AppServiceProvider::boot()
Event::listen(ClientConnected::class, LogOpcUaConnection::class);
```

### Using Queued Listeners

For heavy processing (database writes, HTTP calls, notifications), use queued listeners so the OPC UA client doesn't block:

```php
namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use PhpOpcua\Client\Event\DataChangeReceived;

class StoreDataChange implements ShouldQueue
{
    public $queue = 'opcua';

    public function handle(DataChangeReceived $event): void
    {
        DB::table('sensor_readings')->insert([
            'value' => $event->dataValue->getValue(),
            'client_handle' => $event->clientHandle,
            'subscription_id' => $event->subscriptionId,
        ]);
    }
}
```

## Event Reference

### Connection Lifecycle

| Event | Properties | When |
|---|---|---|
| `ClientConnecting` | `client`, `endpointUrl` | Before TCP connection |
| `ClientConnected` | `client`, `endpointUrl` | After successful connection |
| `ConnectionFailed` | `client`, `endpointUrl`, `exception` | Connection attempt failed |
| `ClientReconnecting` | `client` | Before auto-retry reconnection |
| `ClientDisconnecting` | `client` | Before graceful disconnect |
| `ClientDisconnected` | `client` | After disconnect |

### Session

| Event | Properties | When |
|---|---|---|
| `SessionCreated` | `client`, `sessionId`, `authenticationToken` | OPC UA session created |
| `SessionActivated` | `client`, `sessionId` | Session activated with credentials |
| `SessionClosed` | `client`, `sessionId` | Session closed |

### Secure Channel

| Event | Properties | When |
|---|---|---|
| `SecureChannelOpened` | `client`, `securityPolicy`, `securityMode` | Secure channel established |
| `SecureChannelClosed` | `client` | Secure channel closed |

### Read / Write

| Event | Properties | When |
|---|---|---|
| `NodeValueRead` | `client`, `nodeId`, `attributeId`, `dataValue` | After reading a node value |
| `NodeValueWritten` | `client`, `nodeId`, `value`, `type`, `statusCode` | After successful write |
| `NodeValueWriteFailed` | `client`, `nodeId`, `value`, `type`, `statusCode` | Write returned bad status |
| `WriteTypeDetecting` | `client`, `nodeId` | Before auto-detecting write type |
| `WriteTypeDetected` | `client`, `nodeId`, `builtinType` | Write type resolved |

### Browse

| Event | Properties | When |
|---|---|---|
| `NodeBrowsed` | `client`, `nodeId`, `direction`, `resultCount` | After browsing a node |

### Subscriptions

| Event | Properties | When |
|---|---|---|
| `SubscriptionCreated` | `client`, `subscriptionId`, `revisedPublishingInterval`, `revisedLifetimeCount`, `revisedMaxKeepAliveCount` | Subscription created |
| `SubscriptionDeleted` | `client`, `subscriptionId` | Subscription deleted |
| `SubscriptionTransferred` | `client`, `subscriptionId` | Subscription transferred |
| `SubscriptionKeepAlive` | `client`, `subscriptionId`, `sequenceNumber` | Keep-alive received (no data) |
| `MonitoredItemCreated` | `client`, `subscriptionId`, `clientHandle`, `monitoredItemId` | Monitored item created |
| `MonitoredItemModified` | `client`, `subscriptionId`, `monitoredItemId` | Monitored item modified |
| `MonitoredItemDeleted` | `client`, `subscriptionId`, `monitoredItemId` | Monitored item deleted |
| `TriggeringConfigured` | `client`, `subscriptionId`, `triggeringItemId` | Triggering links configured |
| `DataChangeReceived` | `client`, `subscriptionId`, `sequenceNumber`, `clientHandle`, `dataValue` | Data change notification |
| `EventNotificationReceived` | `client`, `subscriptionId`, `sequenceNumber`, `clientHandle`, `eventFields` | Event notification received |
| `PublishResponseReceived` | `client`, `subscriptionId`, `sequenceNumber` | Raw publish response |

### Alarms

| Event | Properties | When |
|---|---|---|
| `AlarmActivated` | `client`, `subscriptionId`, `clientHandle`, `sourceName?`, `severity?`, `message?` | Alarm entered active state |
| `AlarmDeactivated` | `client`, `subscriptionId`, `clientHandle`, `sourceName?`, `severity?`, `message?` | Alarm returned to normal |
| `AlarmAcknowledged` | `client`, `subscriptionId`, `clientHandle`, `sourceName?`, `message?` | Alarm acknowledged |
| `AlarmConfirmed` | `client`, `subscriptionId`, `clientHandle`, `sourceName?`, `message?` | Alarm confirmed |
| `AlarmShelved` | `client`, `subscriptionId`, `clientHandle`, `sourceName?`, `message?` | Alarm shelved |
| `AlarmSeverityChanged` | `client`, `subscriptionId`, `clientHandle`, `sourceName?`, `severity?`, `previousSeverity?` | Alarm severity changed |
| `AlarmEventReceived` | `client`, `subscriptionId`, `clientHandle`, `eventFields` | Generic alarm event |
| `LimitAlarmExceeded` | `client`, `subscriptionId`, `clientHandle`, `sourceName?`, `severity?`, `message?` | Limit alarm threshold exceeded |
| `OffNormalAlarmTriggered` | `client`, `subscriptionId`, `clientHandle`, `sourceName?`, `severity?`, `message?` | Off-normal condition detected |

### Cache

| Event | Properties | When |
|---|---|---|
| `CacheHit` | `client`, `key` | Value found in cache |
| `CacheMiss` | `client`, `key` | Value not in cache, fetching from server |

### Retry

| Event | Properties | When |
|---|---|---|
| `RetryAttempt` | `client`, `attempt`, `maxRetries`, `exception` | Before retry attempt |
| `RetryExhausted` | `client`, `attempt`, `maxRetries`, `exception` | All retries failed |

### Certificate Trust

| Event | Properties | When |
|---|---|---|
| `ServerCertificateTrusted` | `client`, `fingerprint` | Server cert found in trust store |
| `ServerCertificateAutoAccepted` | `client`, `fingerprint` | Server cert auto-accepted and saved |
| `ServerCertificateRejected` | `client`, `fingerprint`, `reason` | Server cert rejected |
| `ServerCertificateManuallyTrusted` | `client`, `fingerprint` | Cert manually added to trust store |
| `ServerCertificateRemoved` | `client`, `fingerprint` | Cert removed from trust store |

### Type Discovery

| Event | Properties | When |
|---|---|---|
| `DataTypesDiscovered` | `client`, `count` | After `discoverDataTypes()` completes |

## Practical Examples

### Log all connection events

```php
use PhpOpcua\Client\Event\ClientConnected;
use PhpOpcua\Client\Event\ClientDisconnected;
use PhpOpcua\Client\Event\ConnectionFailed;

Event::listen(ClientConnected::class, function ($e) {
    logger()->info("OPC UA connected: {$e->endpointUrl}");
});

Event::listen(ClientDisconnected::class, function ($e) {
    logger()->info('OPC UA disconnected');
});

Event::listen(ConnectionFailed::class, function ($e) {
    logger()->error("OPC UA connection failed: {$e->endpointUrl}", [
        'error' => $e->exception->getMessage(),
    ]);
});
```

### Monitor cache performance

```php
use PhpOpcua\Client\Event\CacheHit;
use PhpOpcua\Client\Event\CacheMiss;

$hits = 0;
$misses = 0;

Event::listen(CacheHit::class, function () use (&$hits) { $hits++; });
Event::listen(CacheMiss::class, function () use (&$misses) { $misses++; });
```

### Alert on alarms via notification

```php
use PhpOpcua\Client\Event\AlarmActivated;
use App\Notifications\AlarmTriggered;

Event::listen(AlarmActivated::class, function (AlarmActivated $e) {
    $operators = User::role('operator')->get();

    Notification::send($operators, new AlarmTriggered(
        source: $e->sourceName,
        severity: $e->severity,
        message: $e->message,
    ));
});
```

### Track write failures

```php
use PhpOpcua\Client\Event\NodeValueWriteFailed;

Event::listen(NodeValueWriteFailed::class, function (NodeValueWriteFailed $e) {
    logger()->warning("Write failed on {$e->nodeId}", [
        'value' => $e->value,
        'status' => sprintf('0x%08X', $e->statusCode),
    ]);
});
```

### Retry observability

```php
use PhpOpcua\Client\Event\RetryAttempt;
use PhpOpcua\Client\Event\RetryExhausted;

Event::listen(RetryAttempt::class, function (RetryAttempt $e) {
    logger()->warning("Retry {$e->attempt}/{$e->maxRetries}: {$e->exception->getMessage()}");
});

Event::listen(RetryExhausted::class, function (RetryExhausted $e) {
    logger()->error("All {$e->maxRetries} retries exhausted", [
        'error' => $e->exception->getMessage(),
    ]);
});
```

### Broadcast data changes to the frontend

```php
use PhpOpcua\Client\Event\DataChangeReceived;
use Illuminate\Support\Facades\Broadcast;

Event::listen(DataChangeReceived::class, function (DataChangeReceived $e) {
    broadcast(new \App\Events\SensorUpdated(
        clientHandle: $e->clientHandle,
        value: $e->dataValue->getValue(),
    ));
});
```

## Disabling Events

To disable event dispatching for a specific client, pass `null` as the event dispatcher in the connection config:

```php
$client = Opcua::connectTo('opc.tcp://...', [
    'event_dispatcher' => null,
]);
```

Or use the `NullEventDispatcher`:

```php
use PhpOpcua\Client\Event\NullEventDispatcher;

$client = Opcua::connect();
$client->setEventDispatcher(new NullEventDispatcher());
```
