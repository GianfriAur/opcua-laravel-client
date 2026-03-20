# Changelog

## [2.0.0] - 2026-03-20

### Changed

- **BREAKING:** Updated dependency `gianfriaur/opcua-php-client` from `^1.1.0` to `^2.0.0`.
- **BREAKING:** Updated dependency `gianfriaur/opcua-php-client-session-manager` from `^1.1.0` to `^2.0.0`.
- **BREAKING:** `browse()` and `browseWithContinuation()` `$direction` parameter changed from `int` to `BrowseDirection` enum. Calls using `direction: 0` or `direction: 1` must be updated to `BrowseDirection::Forward` / `BrowseDirection::Inverse`.
- Updated CI test server suite from `GianfriAur/opcua-test-server-suite@v1.1.2` to `@v1.1.4`.

### Added

- **Timeout configuration.** New `timeout` key in connection config (seconds). Also available via `Opcua::setTimeout()` fluent API. Default: `5.0`.
- **Auto-retry configuration.** New `auto_retry` key in connection config. Automatically reconnects and retries on `ConnectionException`. Also available via `Opcua::setAutoRetry()`. Default: `0` before connect, `1` after connect.
- **Automatic batching configuration.** New `batch_size` key in connection config. When enabled, `readMulti`/`writeMulti` calls are transparently split into batches when exceeding server limits. Set to `0` to disable. Also available via `Opcua::setBatchSize()`.
- **Browse max depth configuration.** New `browse_max_depth` key in connection config. Controls default depth for `browseRecursive()`. Also available via `Opcua::setDefaultBrowseMaxDepth()`. Default: `10`.
- **Connection state management.** New methods exposed via facade: `reconnect()`, `isConnected()`, `getConnectionState()`.
- **browseAll().** Browse with automatic continuation point handling — returns all references in one call.
- **browseRecursive().** Recursive tree traversal returning `BrowseNode[]` with configurable depth and cycle detection.
- **translateBrowsePaths().** OPC UA TranslateBrowsePathsToNodeIds service for batch path resolution.
- **resolveNodeId().** Human-readable path resolution (e.g. `/Objects/Server/ServerStatus`).
- **Server operation limits discovery.** `getServerMaxNodesPerRead()` and `getServerMaxNodesPerWrite()` expose discovered limits.
- **historyReadProcessed()** and **historyReadAtTime()** methods exposed via facade.
- Updated `Opcua` facade PHPDoc with all new v2.0 method signatures for IDE autocompletion.
- New `config/opcua.php` keys: `timeout`, `auto_retry`, `batch_size`, `browse_max_depth` per connection.
- `OpcuaManager::configureClient()` applies new v2.0 settings (timeout, auto-retry, batching, browse depth) to client instances.
- Unit tests for `configureClient` v2.0 options (timeout, auto_retry, batch_size, browse_max_depth) — all null/non-null paths.
- Integration tests: `ConnectionStateTest`, `TimeoutTest`, `AutoRetryTest`, `BatchingTest`, `BrowseRecursiveTest`, `TranslateBrowsePathTest`, `HistoryReadAdvancedTest`.

## [1.1.0] - 2026-03-18

### Changed

- Updated dependencies `gianfriaur/opcua-php-client` and `gianfriaur/opcua-php-client-session-manager` from `^1.0.1` to `^1.1.0`.

### Added

- **Auto-generated client certificate support.** When a connection is configured with a `security_policy` and `security_mode` but without `client_certificate`/`client_key`, the underlying client automatically generates an in-memory self-signed certificate. The behaviour is fully transparent — no changes to the config file or application code are required.
- Config comment on `client_certificate`/`client_key` keys documenting the auto-generation fallback.
- Unit tests (`configureClient certificate behavior`) covering: no call to `setClientCertificate` when cert is absent, no call when only one of cert/key is provided, correct call when both are present, and correct forwarding of the optional `ca_certificate`.
- Integration tests for connecting with `Basic256Sha256`/`SignAndEncrypt` and no explicit client certificate, in both direct mode and managed (session manager daemon) mode.

## [1.0.1] - 2026-03-16

### Added

- Initial release. Laravel service provider, facade, `OpcuaManager` (multi-connection, session-manager auto-detection), and `opcua:session` Artisan command.
