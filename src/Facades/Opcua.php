<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaLaravel\Facades;

use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Illuminate\Support\Facades\Facade;

/**
 * @method static OpcUaClientInterface connection(?string $name = null)
 * @method static OpcUaClientInterface connect(?string $name = null)
 * @method static OpcUaClientInterface connectTo(string $endpointUrl, array $config = [], ?string $as = null)
 * @method static void disconnect(?string $name = null)
 * @method static void disconnectAll()
 * @method static bool isSessionManagerRunning()
 * @method static string getDefaultConnection()
 *
 * Proxied to default connection:
 * @method static void connect(string $endpointUrl)
 * @method static void disconnect()
 * @method static void reconnect()
 * @method static bool isConnected()
 * @method static ConnectionState getConnectionState()
 * @method static self setTimeout(float $timeout)
 * @method static float getTimeout()
 * @method static self setAutoRetry(int $maxRetries)
 * @method static int getAutoRetry()
 * @method static self setBatchSize(int $batchSize)
 * @method static int|null getBatchSize()
 * @method static int|null getServerMaxNodesPerRead()
 * @method static int|null getServerMaxNodesPerWrite()
 * @method static EndpointDescription[] getEndpoints(string $endpointUrl)
 * @method static ReferenceDescription[] browse(NodeId $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, int $nodeClassMask = 0)
 * @method static array browseWithContinuation(NodeId $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, int $nodeClassMask = 0)
 * @method static array browseNext(string $continuationPoint)
 * @method static ReferenceDescription[] browseAll(NodeId $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, int $nodeClassMask = 0)
 * @method static self setDefaultBrowseMaxDepth(int $maxDepth)
 * @method static int getDefaultBrowseMaxDepth()
 * @method static BrowseNode[] browseRecursive(NodeId $nodeId, BrowseDirection $direction = BrowseDirection::Forward, ?int $maxDepth = null, ?NodeId $referenceTypeId = null, bool $includeSubtypes = true, int $nodeClassMask = 0)
 * @method static array translateBrowsePaths(array $browsePaths)
 * @method static NodeId resolveNodeId(string $path, ?NodeId $startingNodeId = null)
 * @method static DataValue read(NodeId $nodeId, int $attributeId = 13)
 * @method static DataValue[] readMulti(array $items)
 * @method static int write(NodeId $nodeId, mixed $value, BuiltinType $type)
 * @method static int[] writeMulti(array $items)
 * @method static array call(NodeId $objectId, NodeId $methodId, array $inputArguments = [])
 * @method static array createSubscription(float $publishingInterval = 500.0, int $lifetimeCount = 2400, int $maxKeepAliveCount = 10, int $maxNotificationsPerPublish = 0, bool $publishingEnabled = true, int $priority = 0)
 * @method static array createMonitoredItems(int $subscriptionId, array $items)
 * @method static array createEventMonitoredItem(int $subscriptionId, NodeId $nodeId, array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'], int $clientHandle = 1)
 * @method static array deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds)
 * @method static int deleteSubscription(int $subscriptionId)
 * @method static array publish(array $acknowledgements = [])
 * @method static DataValue[] historyReadRaw(NodeId $nodeId, ?\DateTimeImmutable $startTime = null, ?\DateTimeImmutable $endTime = null, int $numValuesPerNode = 0, bool $returnBounds = false)
 * @method static DataValue[] historyReadProcessed(NodeId $nodeId, \DateTimeImmutable $startTime, \DateTimeImmutable $endTime, float $processingInterval, NodeId $aggregateType)
 * @method static DataValue[] historyReadAtTime(NodeId $nodeId, array $timestamps)
 *
 * @see OpcuaManager
 */
class Opcua extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OpcuaManager::class;
    }
}
