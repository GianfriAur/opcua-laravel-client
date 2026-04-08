<?php

declare(strict_types=1);

use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\LaravelOpcua\Commands\SessionCommand;
use Psr\EventDispatcher\EventDispatcherInterface;

if (!function_exists('app')) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        $container = \Illuminate\Container\Container::getInstance();
        if ($abstract === null) {
            return $container;
        }
        return $container->make($abstract, $parameters);
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        $config = app('config');
        if ($key === null) {
            return $config;
        }
        return $config->get($key, $default);
    }
}

if (!function_exists('windows_os')) {
    function windows_os(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

function makeSessionCommandApp(array $configOverrides = []): Container
{
    $app = Mockery::mock(Container::class . '[runningUnitTests]');
    $app->shouldReceive('runningUnitTests')->andReturn(true);
    Container::setInstance($app);

    $app->instance('app', $app);

    $smConfig = array_merge([
        'enabled' => false,
        'socket_path' => sys_get_temp_dir() . '/opcua-cmd-test-' . uniqid() . '.sock',
        'timeout' => 600,
        'cleanup_interval' => 30,
        'auth_token' => null,
        'max_sessions' => 100,
        'socket_mode' => 0600,
        'allowed_cert_dirs' => null,
        'log_channel' => 'stack',
        'cache_store' => 'array',
    ], $configOverrides);

    $config = new Repository([
        'opcua' => [
            'default' => 'default',
            'session_manager' => $smConfig,
            'connections' => [
                'default' => ['endpoint' => 'opc.tcp://localhost:4840'],
            ],
        ],
    ]);

    $app->instance('config', $config);
    $app->instance('events', new Dispatcher($app));

    $logger = new NullLogger();
    $app->instance(LoggerInterface::class, $logger);

    $logManager = Mockery::mock();
    $logManager->shouldReceive('channel')->andReturn($logger);
    $app->instance('log', $logManager);

    $cacheMock = Mockery::mock(CacheInterface::class);
    $cacheManager = Mockery::mock();
    $cacheManager->shouldReceive('store')->andReturn($cacheMock);
    $app->instance('cache', $cacheManager);
    $app->instance(CacheInterface::class, $cacheMock);

    $eventDispatcher = Mockery::mock(EventDispatcherInterface::class);
    $app->instance(EventDispatcherInterface::class, $eventDispatcher);

    return $app;
}

/**
 * Creates a testable SessionCommand that captures the daemon parameters instead of running it.
 */
function makeTestableCommand(array $configOverrides = []): array
{
    $app = makeSessionCommandApp($configOverrides);

    $captured = [];

    $command = new class extends SessionCommand {
        public array $capturedArgs = [];
        public ?SessionManagerDaemon $daemonMock = null;

        protected function createDaemon(
            string                                $socketPath,
            int                                   $timeout,
            int                                   $cleanupInterval,
            ?string                               $authToken,
            int                                   $maxSessions,
            int                                   $socketMode,
            ?array                                $allowedCertDirs,
            LoggerInterface                       $logger,
            ?CacheInterface                       $clientCache,
            ?EventDispatcherInterface             $clientEventDispatcher = null,
            bool                                  $autoPublish = false,
        ): SessionManagerDaemon {
            $this->capturedArgs = [
                'socketPath' => $socketPath,
                'timeout' => $timeout,
                'cleanupInterval' => $cleanupInterval,
                'authToken' => $authToken,
                'maxSessions' => $maxSessions,
                'socketMode' => $socketMode,
                'allowedCertDirs' => $allowedCertDirs,
                'logger' => $logger,
                'clientCache' => $clientCache,
                'clientEventDispatcher' => $clientEventDispatcher,
                'autoPublish' => $autoPublish,
            ];

            $this->daemonMock = Mockery::mock(SessionManagerDaemon::class);
            $this->daemonMock->shouldReceive('run')->once();
            $this->daemonMock->shouldReceive('autoConnect')->zeroOrMoreTimes();
            return $this->daemonMock;
        }
    };

    $command->setLaravel($app);

    return [$command, $app];
}

function runCommand(object $command, array $options = []): int
{
    $input = new \Symfony\Component\Console\Input\ArrayInput($options);
    $output = new \Symfony\Component\Console\Output\BufferedOutput();

    $command->run($input, $output);

    return 0;
}

describe('SessionCommand', function () {

    afterEach(function () {
        Container::setInstance(null);
        Mockery::close();
    });

    describe('default config values', function () {

        it('passes config defaults to the daemon', function () {
            [$command] = makeTestableCommand();

            runCommand($command);

            expect($command->capturedArgs['timeout'])->toBe(600);
            expect($command->capturedArgs['cleanupInterval'])->toBe(30);
            expect($command->capturedArgs['maxSessions'])->toBe(100);
            expect($command->capturedArgs['socketMode'])->toBe(0600);
            expect($command->capturedArgs['authToken'])->toBeNull();
            expect($command->capturedArgs['allowedCertDirs'])->toBeNull();
        });

        it('passes socket path from config', function () {
            [$command] = makeTestableCommand([
                'socket_path' => '/tmp/custom-test.sock',
            ]);

            runCommand($command);

            expect($command->capturedArgs['socketPath'])->toBe('/tmp/custom-test.sock');
        });

        it('passes auth token from config', function () {
            [$command] = makeTestableCommand([
                'auth_token' => 'my-secret-token',
            ]);

            runCommand($command);

            expect($command->capturedArgs['authToken'])->toBe('my-secret-token');
        });

        it('passes allowed cert dirs from config', function () {
            [$command] = makeTestableCommand([
                'allowed_cert_dirs' => ['/etc/opcua/certs'],
            ]);

            runCommand($command);

            expect($command->capturedArgs['allowedCertDirs'])->toBe(['/etc/opcua/certs']);
        });
    });

    describe('CLI option overrides', function () {

        it('--timeout overrides config', function () {
            [$command] = makeTestableCommand();

            runCommand($command, ['--timeout' => '300']);

            expect($command->capturedArgs['timeout'])->toBe(300);
        });

        it('--cleanup-interval overrides config', function () {
            [$command] = makeTestableCommand();

            runCommand($command, ['--cleanup-interval' => '10']);

            expect($command->capturedArgs['cleanupInterval'])->toBe(10);
        });

        it('--max-sessions overrides config', function () {
            [$command] = makeTestableCommand();

            runCommand($command, ['--max-sessions' => '50']);

            expect($command->capturedArgs['maxSessions'])->toBe(50);
        });

        it('--socket-mode overrides config as octal', function () {
            [$command] = makeTestableCommand();

            runCommand($command, ['--socket-mode' => '0660']);

            expect($command->capturedArgs['socketMode'])->toBe(0660);
        });
    });

    describe('logger resolution', function () {

        it('resolves logger via app log manager', function () {
            [$command] = makeTestableCommand();

            runCommand($command);

            expect($command->capturedArgs['logger'])->toBeInstanceOf(LoggerInterface::class);
        });

        it('passes log-channel option to log manager', function () {
            $app = makeSessionCommandApp();

            $logManager = Mockery::mock();
            $logManager->shouldReceive('channel')
                ->with('stderr')
                ->once()
                ->andReturn(new NullLogger());
            $app->instance('log', $logManager);

            $command = new class extends SessionCommand {
                public array $capturedArgs = [];

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
                ): SessionManagerDaemon {
                    $this->capturedArgs = compact('logger');
                    $mock = Mockery::mock(SessionManagerDaemon::class);
                    $mock->shouldReceive('run')->once();
                    $mock->shouldReceive('autoConnect')->zeroOrMoreTimes();
                    return $mock;
                }
            };
            $command->setLaravel($app);

            runCommand($command, ['--log-channel' => 'stderr']);
        });
    });

    describe('cache resolution', function () {

        it('resolves cache via app cache manager', function () {
            [$command] = makeTestableCommand();

            runCommand($command);

            expect($command->capturedArgs['clientCache'])->toBeInstanceOf(CacheInterface::class);
        });

        it('passes cache-store option to cache manager', function () {
            $app = makeSessionCommandApp();

            $cacheMock = Mockery::mock(CacheInterface::class);
            $cacheManager = Mockery::mock();
            $cacheManager->shouldReceive('store')
                ->with('redis')
                ->once()
                ->andReturn($cacheMock);
            $app->instance('cache', $cacheManager);

            $command = new class extends SessionCommand {
                public array $capturedArgs = [];

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
                ): SessionManagerDaemon {
                    $this->capturedArgs = compact('clientCache');
                    $mock = Mockery::mock(SessionManagerDaemon::class);
                    $mock->shouldReceive('run')->once();
                    $mock->shouldReceive('autoConnect')->zeroOrMoreTimes();
                    return $mock;
                }
            };
            $command->setLaravel($app);

            runCommand($command, ['--cache-store' => 'redis']);
        });
    });

    describe('output', function () {

        it('displays the startup info table', function () {
            [$command] = makeTestableCommand([
                'socket_path' => '/tmp/test-output.sock',
                'timeout' => 120,
                'auth_token' => 'secret',
            ]);

            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            $command->run($input, $output);

            $rendered = $output->fetch();

            expect($rendered)->toContain('Starting OPC UA Session Manager');
            expect($rendered)->toContain('/tmp/test-output.sock');
            expect($rendered)->toContain('120s');
            expect($rendered)->toContain('configured');
        });

        it('shows "none" when auth token is null', function () {
            [$command] = makeTestableCommand([
                'auth_token' => null,
            ]);

            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            $command->run($input, $output);

            $rendered = $output->fetch();
            expect($rendered)->toContain('none');
        });

        it('shows "any" when allowed cert dirs is null', function () {
            [$command] = makeTestableCommand([
                'allowed_cert_dirs' => null,
            ]);

            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            $command->run($input, $output);

            $rendered = $output->fetch();
            expect($rendered)->toContain('any');
        });
    });

    describe('daemon creation', function () {

        it('calls daemon run()', function () {
            [$command] = makeTestableCommand();

            runCommand($command);

            expect(true)->toBeTrue();
        });

        it('creates socket directory if missing', function () {
            $dir = sys_get_temp_dir() . '/opcua-cmd-test-' . uniqid() . '/nested';
            $sockPath = $dir . '/test.sock';

            [$command] = makeTestableCommand([
                'socket_path' => $sockPath,
            ]);

            runCommand($command);

            expect(is_dir($dir))->toBeTrue();

            @rmdir($dir);
            @rmdir(dirname($dir));
        });
    });

    describe('auto-publish', function () {

        it('passes autoPublish=false by default', function () {
            [$command] = makeTestableCommand();

            runCommand($command);

            expect($command->capturedArgs['autoPublish'])->toBeFalse();
            expect($command->capturedArgs['clientEventDispatcher'])->toBeNull();
        });

        it('passes autoPublish=true and event dispatcher when enabled', function () {
            [$command] = makeTestableCommand(['auto_publish' => true]);

            runCommand($command);

            expect($command->capturedArgs['autoPublish'])->toBeTrue();
            expect($command->capturedArgs['clientEventDispatcher'])->toBeInstanceOf(EventDispatcherInterface::class);
        });

        it('displays auto-publish status in startup table', function () {
            [$command] = makeTestableCommand(['auto_publish' => true]);

            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            $command->run($input, $output);

            $rendered = $output->fetch();
            expect($rendered)->toContain('enabled');
        });

        it('displays disabled when auto-publish is off', function () {
            [$command] = makeTestableCommand(['auto_publish' => false]);

            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            $command->run($input, $output);

            $rendered = $output->fetch();
            expect($rendered)->toContain('disabled');
        });
    });

    describe('auto-connect', function () {

        it('calls autoConnect on daemon when connections have auto_connect', function () {
            $app = makeSessionCommandApp(['auto_publish' => true]);
            $config = $app->make('config');
            $config->set('opcua.connections', [
                'plc1' => [
                    'endpoint' => 'opc.tcp://plc1:4840',
                    'auto_connect' => true,
                    'subscriptions' => [
                        [
                            'publishing_interval' => 500.0,
                            'monitored_items' => [
                                ['node_id' => 'ns=2;s=Temperature', 'client_handle' => 1],
                            ],
                        ],
                    ],
                ],
            ]);

            $command = new class extends SessionCommand {
                public array $capturedArgs = [];
                public ?array $autoConnectCalled = null;

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
                ): SessionManagerDaemon {
                    $this->capturedArgs = compact('autoPublish');
                    $mock = Mockery::mock(SessionManagerDaemon::class);
                    $mock->shouldReceive('run')->once();
                    $mock->shouldReceive('autoConnect')->once()->andReturnUsing(function ($connections) {
                        $this->autoConnectCalled = $connections;
                    });
                    return $mock;
                }
            };
            $command->setLaravel($app);
            runCommand($command);

            expect($command->autoConnectCalled)->toHaveKey('plc1');
            expect($command->autoConnectCalled['plc1']['endpoint'])->toBe('opc.tcp://plc1:4840');
            expect($command->autoConnectCalled['plc1']['subscriptions'])->toHaveCount(1);
        });

        it('skips connections without auto_connect', function () {
            $app = makeSessionCommandApp(['auto_publish' => true]);
            $config = $app->make('config');
            $config->set('opcua.connections', [
                'plc1' => [
                    'endpoint' => 'opc.tcp://plc1:4840',
                    'auto_connect' => true,
                    'subscriptions' => [['monitored_items' => [['node_id' => 'i=1', 'client_handle' => 1]]]],
                ],
                'historian' => [
                    'endpoint' => 'opc.tcp://historian:4840',
                ],
                'plc2' => [
                    'endpoint' => 'opc.tcp://plc2:4840',
                    'auto_connect' => false,
                    'subscriptions' => [['monitored_items' => [['node_id' => 'i=2', 'client_handle' => 2]]]],
                ],
            ]);

            $command = new class extends SessionCommand {
                public ?array $autoConnectCalled = null;

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
                ): SessionManagerDaemon {
                    $mock = Mockery::mock(SessionManagerDaemon::class);
                    $mock->shouldReceive('run')->once();
                    $mock->shouldReceive('autoConnect')->once()->andReturnUsing(function ($connections) {
                        $this->autoConnectCalled = $connections;
                    });
                    return $mock;
                }
            };
            $command->setLaravel($app);
            runCommand($command);

            expect($command->autoConnectCalled)->toHaveCount(1);
            expect($command->autoConnectCalled)->toHaveKey('plc1');
            expect($command->autoConnectCalled)->not->toHaveKey('historian');
            expect($command->autoConnectCalled)->not->toHaveKey('plc2');
        });

        it('does not call autoConnect when auto_publish is disabled', function () {
            $app = makeSessionCommandApp(['auto_publish' => false]);
            $config = $app->make('config');
            $config->set('opcua.connections', [
                'plc1' => [
                    'endpoint' => 'opc.tcp://plc1:4840',
                    'auto_connect' => true,
                    'subscriptions' => [['monitored_items' => [['node_id' => 'i=1', 'client_handle' => 1]]]],
                ],
            ]);

            $command = new class extends SessionCommand {
                public bool $autoConnectWasCalled = false;

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
                ): SessionManagerDaemon {
                    $mock = Mockery::mock(SessionManagerDaemon::class);
                    $mock->shouldReceive('run')->once();
                    $mock->shouldReceive('autoConnect')->never();
                    return $mock;
                }
            };
            $command->setLaravel($app);
            runCommand($command);
        });
    });

    describe('mapToDaemonConfig', function () {

        it('maps Laravel config keys to daemon format', function () {
            $command = new SessionCommand();
            $method = new ReflectionMethod(SessionCommand::class, 'mapToDaemonConfig');

            $result = $method->invoke($command, [
                'security_policy' => 'Basic256Sha256',
                'security_mode' => 'SignAndEncrypt',
                'username' => 'admin',
                'password' => 'secret',
                'timeout' => 3.0,
                'auto_retry' => 5,
                'batch_size' => 100,
                'browse_max_depth' => 15,
                'trust_store_path' => '/certs',
                'trust_policy' => 'fingerprint',
                'auto_accept' => true,
                'auto_accept_force' => true,
                'auto_detect_write_type' => true,
                'read_metadata_cache' => false,
            ]);

            expect($result['securityPolicy'])->toBe(SecurityPolicy::Basic256Sha256->value);
            expect($result['securityMode'])->toBe(SecurityMode::SignAndEncrypt->value);
            expect($result['username'])->toBe('admin');
            expect($result['password'])->toBe('secret');
            expect($result['opcuaTimeout'])->toBe(3.0);
            expect($result['autoRetry'])->toBe(5);
            expect($result['batchSize'])->toBe(100);
            expect($result['defaultBrowseMaxDepth'])->toBe(15);
            expect($result['trustStorePath'])->toBe('/certs');
            expect($result['trustPolicy'])->toBe('fingerprint');
            expect($result['autoAccept'])->toBeTrue();
            expect($result['autoAcceptForce'])->toBeTrue();
            expect($result['autoDetectWriteType'])->toBeTrue();
            expect($result['readMetadataCache'])->toBeFalse();
        });

        it('skips None security policy and mode', function () {
            $command = new SessionCommand();
            $method = new ReflectionMethod(SessionCommand::class, 'mapToDaemonConfig');

            $result = $method->invoke($command, [
                'security_policy' => 'None',
                'security_mode' => 'None',
            ]);

            expect($result)->not->toHaveKey('securityPolicy');
            expect($result)->not->toHaveKey('securityMode');
        });

        it('skips empty/null values', function () {
            $command = new SessionCommand();
            $method = new ReflectionMethod(SessionCommand::class, 'mapToDaemonConfig');

            $result = $method->invoke($command, [
                'username' => null,
                'password' => null,
                'client_certificate' => null,
            ]);

            expect($result)->not->toHaveKey('username');
            expect($result)->not->toHaveKey('password');
            expect($result)->not->toHaveKey('clientCertPath');
        });

        it('maps certificate paths', function () {
            $command = new SessionCommand();
            $method = new ReflectionMethod(SessionCommand::class, 'mapToDaemonConfig');

            $result = $method->invoke($command, [
                'client_certificate' => '/certs/client.pem',
                'client_key' => '/certs/client.key',
                'ca_certificate' => '/certs/ca.pem',
                'user_certificate' => '/certs/user.pem',
                'user_key' => '/certs/user.key',
            ]);

            expect($result['clientCertPath'])->toBe('/certs/client.pem');
            expect($result['clientKeyPath'])->toBe('/certs/client.key');
            expect($result['caCertPath'])->toBe('/certs/ca.pem');
            expect($result['userCertPath'])->toBe('/certs/user.pem');
            expect($result['userKeyPath'])->toBe('/certs/user.key');
        });
    });

    describe('buildAutoConnectConfig', function () {

        it('filters connections by auto_connect and subscriptions', function () {
            $app = makeSessionCommandApp();
            $config = $app->make('config');
            $config->set('opcua.connections', [
                'active' => [
                    'endpoint' => 'opc.tcp://plc:4840',
                    'auto_connect' => true,
                    'subscriptions' => [['monitored_items' => [['node_id' => 'i=1', 'client_handle' => 1]]]],
                ],
                'no-auto' => [
                    'endpoint' => 'opc.tcp://other:4840',
                    'subscriptions' => [['monitored_items' => [['node_id' => 'i=2', 'client_handle' => 2]]]],
                ],
                'no-subs' => [
                    'endpoint' => 'opc.tcp://bare:4840',
                    'auto_connect' => true,
                ],
            ]);

            $command = new SessionCommand();
            $command->setLaravel($app);
            $method = new ReflectionMethod(SessionCommand::class, 'buildAutoConnectConfig');

            $result = $method->invoke($command);

            expect($result)->toHaveCount(1);
            expect($result)->toHaveKey('active');
            expect($result['active']['endpoint'])->toBe('opc.tcp://plc:4840');
        });
    });
});
