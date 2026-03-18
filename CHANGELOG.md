# Changelog

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
