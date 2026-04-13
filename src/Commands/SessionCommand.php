<?php

declare(strict_types=1);

namespace PhpOpcua\LaravelOpcua\Commands;

use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use Illuminate\Console\Command;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Artisan command to start the OPC UA session manager daemon.
 */
class SessionCommand extends Command
{
    protected $signature = 'opcua:session
        {--timeout= : Session inactivity timeout in seconds}
        {--cleanup-interval= : Cleanup check interval in seconds}
        {--max-sessions= : Maximum concurrent sessions}
        {--socket-mode= : Socket file permissions (octal)}
        {--log-channel= : Laravel log channel name}
        {--cache-store= : Laravel cache store name}';

    protected $description = 'Start the OPC UA session manager daemon';

    /**
     * @return int
     */
    public function handle(): int
    {
        $config = config('opcua.session_manager');

        $socketPath = $config['socket_path'];
        $timeout = $this->option('timeout') ?? $config['timeout'];
        $cleanupInterval = $this->option('cleanup-interval') ?? $config['cleanup_interval'];
        $maxSessions = $this->option('max-sessions') ?? $config['max_sessions'];
        $socketMode = $this->option('socket-mode')
            ? intval($this->option('socket-mode'), 8)
            : $config['socket_mode'];

        $allowedCertDirs = $config['allowed_cert_dirs'];
        $authToken = $config['auth_token'];

        $logger = $this->resolveLogger($config);
        $cache = $this->resolveCache($config);

        $autoPublish = (bool) ($config['auto_publish'] ?? false);
        $eventDispatcher = $autoPublish ? $this->resolveEventDispatcher() : null;

        $socketDir = dirname($socketPath);
        if (!is_dir($socketDir)) {
            mkdir($socketDir, 0755, true);
        }

        $logChannelName = $this->option('log-channel') ?? $config['log_channel'] ?? 'default';
        $cacheStoreName = $this->option('cache-store') ?? $config['cache_store'] ?? 'default';

        $this->info('Starting OPC UA Session Manager...');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Socket', $socketPath],
                ['Timeout', $timeout . 's'],
                ['Cleanup Interval', $cleanupInterval . 's'],
                ['Max Sessions', $maxSessions],
                ['Socket Mode', sprintf('0%o', $socketMode)],
                ['Auth Token', $authToken ? 'configured' : 'none'],
                ['Cert Dirs', $allowedCertDirs ? implode(', ', $allowedCertDirs) : 'any'],
                ['Log Channel', $logChannelName],
                ['Cache Store', $cacheStoreName],
                ['Auto-publish', $autoPublish ? 'enabled' : 'disabled'],
            ],
        );

        $daemon = $this->createDaemon(
            socketPath: $socketPath,
            timeout: (int)$timeout,
            cleanupInterval: (int)$cleanupInterval,
            authToken: $authToken,
            maxSessions: (int)$maxSessions,
            socketMode: $socketMode,
            allowedCertDirs: $allowedCertDirs,
            logger: $logger,
            clientCache: $cache,
            clientEventDispatcher: $eventDispatcher,
            autoPublish: $autoPublish,
        );

        if ($autoPublish) {
            $autoConnections = $this->buildAutoConnectConfig();
            if (!empty($autoConnections)) {
                $daemon->autoConnect($autoConnections);
                $this->info('Auto-connecting ' . count($autoConnections) . ' connection(s): ' . implode(', ', array_keys($autoConnections)));
            }
        }

        $daemon->run();

        return self::SUCCESS;
    }

    /**
     * Create the session manager daemon instance.
     *
     * @param string $socketPath
     * @param int $timeout
     * @param int $cleanupInterval
     * @param ?string $authToken
     * @param int $maxSessions
     * @param int $socketMode
     * @param ?array $allowedCertDirs
     * @param LoggerInterface $logger
     * @param ?CacheInterface $clientCache
     * @param ?EventDispatcherInterface $clientEventDispatcher
     * @param bool $autoPublish
     * @return SessionManagerDaemon
     */
    protected function createDaemon(
        string                    $socketPath,
        int                       $timeout,
        int                       $cleanupInterval,
        ?string                   $authToken,
        int                       $maxSessions,
        int                       $socketMode,
        ?array                    $allowedCertDirs,
        LoggerInterface           $logger,
        ?CacheInterface           $clientCache,
        ?EventDispatcherInterface $clientEventDispatcher = null,
        bool                      $autoPublish = false,
    ): SessionManagerDaemon
    {
        return new SessionManagerDaemon(
            socketPath: $socketPath,
            timeout: $timeout,
            cleanupInterval: $cleanupInterval,
            authToken: $authToken,
            maxSessions: $maxSessions,
            socketMode: $socketMode,
            allowedCertDirs: $allowedCertDirs,
            logger: $logger,
            clientCache: $clientCache,
            clientEventDispatcher: $clientEventDispatcher,
            autoPublish: $autoPublish,
        );
    }

    /**
     * Resolve the PSR-3 logger for the daemon using a Laravel log channel.
     *
     * @param array $config
     * @return LoggerInterface
     */
    protected function resolveLogger(array $config): LoggerInterface
    {
        $channel = $this->option('log-channel') ?? $config['log_channel'] ?? null;

        return app('log')->channel($channel);
    }

    /**
     * Resolve the PSR-16 cache for the daemon using a Laravel cache store.
     *
     * @param array $config
     * @return CacheInterface
     */
    protected function resolveCache(array $config): CacheInterface
    {
        $store = $this->option('cache-store') ?? $config['cache_store'] ?? null;

        return app('cache')->store($store);
    }

    /**
     * Resolve the PSR-14 event dispatcher from the Laravel container.
     *
     * @return EventDispatcherInterface
     */
    protected function resolveEventDispatcher(): EventDispatcherInterface
    {
        return app(EventDispatcherInterface::class);
    }

    /**
     * Build auto-connect configuration from connections that have auto_connect enabled.
     *
     * @return array<string, array{endpoint: string, config: array, subscriptions: array}>
     */
    protected function buildAutoConnectConfig(): array
    {
        $connections = config('opcua.connections', []);
        $result = [];

        foreach ($connections as $name => $config) {
            if (empty($config['auto_connect']) || empty($config['subscriptions'])) {
                continue;
            }

            $result[$name] = [
                'endpoint' => $config['endpoint'],
                'config' => $this->mapToDaemonConfig($config),
                'subscriptions' => $config['subscriptions'],
            ];
        }

        return $result;
    }

    /**
     * Map Laravel connection config keys to the daemon's CommandHandler config format.
     *
     * @param array $config
     * @return array
     */
    protected function mapToDaemonConfig(array $config): array
    {
        $daemon = [];

        if (!empty($config['security_policy']) && $config['security_policy'] !== 'None') {
            $daemon['securityPolicy'] = $this->resolveSecurityPolicyUri($config['security_policy']);
        }
        if (!empty($config['security_mode']) && $config['security_mode'] !== 'None') {
            $daemon['securityMode'] = $this->resolveSecurityModeValue($config['security_mode']);
        }
        if (!empty($config['username'])) {
            $daemon['username'] = $config['username'];
        }
        if (!empty($config['password'])) {
            $daemon['password'] = $config['password'];
        }
        if (!empty($config['client_certificate'])) {
            $daemon['clientCertPath'] = $config['client_certificate'];
        }
        if (!empty($config['client_key'])) {
            $daemon['clientKeyPath'] = $config['client_key'];
        }
        if (!empty($config['ca_certificate'])) {
            $daemon['caCertPath'] = $config['ca_certificate'];
        }
        if (!empty($config['user_certificate'])) {
            $daemon['userCertPath'] = $config['user_certificate'];
        }
        if (!empty($config['user_key'])) {
            $daemon['userKeyPath'] = $config['user_key'];
        }
        if (isset($config['timeout']) && $config['timeout'] !== null) {
            $daemon['opcuaTimeout'] = (float) $config['timeout'];
        }
        if (isset($config['auto_retry']) && $config['auto_retry'] !== null) {
            $daemon['autoRetry'] = (int) $config['auto_retry'];
        }
        if (isset($config['batch_size']) && $config['batch_size'] !== null) {
            $daemon['batchSize'] = (int) $config['batch_size'];
        }
        if (isset($config['browse_max_depth']) && $config['browse_max_depth'] !== null) {
            $daemon['defaultBrowseMaxDepth'] = (int) $config['browse_max_depth'];
        }
        if (!empty($config['trust_store_path'])) {
            $daemon['trustStorePath'] = $config['trust_store_path'];
        }
        if (!empty($config['trust_policy'])) {
            $daemon['trustPolicy'] = $config['trust_policy'];
        }
        if (!empty($config['auto_accept'])) {
            $daemon['autoAccept'] = true;
        }
        if (!empty($config['auto_accept_force'])) {
            $daemon['autoAcceptForce'] = true;
        }
        if (isset($config['auto_detect_write_type']) && $config['auto_detect_write_type'] !== null) {
            $daemon['autoDetectWriteType'] = (bool) $config['auto_detect_write_type'];
        }
        if (isset($config['read_metadata_cache']) && $config['read_metadata_cache'] !== null) {
            $daemon['readMetadataCache'] = (bool) $config['read_metadata_cache'];
        }

        return $daemon;
    }

    /**
     * Resolve a security policy short name to its OPC UA URI.
     *
     * @param string $policy
     * @return string
     */
    protected function resolveSecurityPolicyUri(string $policy): string
    {
        $map = [
            'None' => SecurityPolicy::None->value,
            'Basic128Rsa15' => SecurityPolicy::Basic128Rsa15->value,
            'Basic256' => SecurityPolicy::Basic256->value,
            'Basic256Sha256' => SecurityPolicy::Basic256Sha256->value,
            'Aes128Sha256RsaOaep' => SecurityPolicy::Aes128Sha256RsaOaep->value,
            'Aes256Sha256RsaPss' => SecurityPolicy::Aes256Sha256RsaPss->value,
            'ECC_nistP256' => SecurityPolicy::EccNistP256->value,
            'ECC_nistP384' => SecurityPolicy::EccNistP384->value,
            'ECC_brainpoolP256r1' => SecurityPolicy::EccBrainpoolP256r1->value,
            'ECC_brainpoolP384r1' => SecurityPolicy::EccBrainpoolP384r1->value,
        ];

        return $map[$policy] ?? $policy;
    }

    /**
     * Resolve a security mode name or integer to its enum integer value.
     *
     * @param string|int $mode
     * @return int
     */
    protected function resolveSecurityModeValue(string|int $mode): int
    {
        if (is_int($mode)) {
            return $mode;
        }

        return match ($mode) {
            'None' => SecurityMode::None->value,
            'Sign' => SecurityMode::Sign->value,
            'SignAndEncrypt' => SecurityMode::SignAndEncrypt->value,
            default => (int) $mode,
        };
    }
}
