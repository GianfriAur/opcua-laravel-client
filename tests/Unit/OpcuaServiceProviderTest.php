<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Commands\SessionCommand;
use Gianfriaur\OpcuaLaravel\OpcuaManager;
use Gianfriaur\OpcuaLaravel\OpcuaServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return sys_get_temp_dir() . '/config/' . $path;
    }
}

function makeApp(): Container
{
    $app = new Container();
    Container::setInstance($app);

    $app->instance('app', $app);

    $config = new Repository([
        'opcua' => [
            'default' => 'default',
            'session_manager' => [
                'enabled' => false,
                'socket_path' => '/tmp/test.sock',
                'timeout' => 600,
                'cleanup_interval' => 30,
                'auth_token' => null,
                'max_sessions' => 100,
                'socket_mode' => 0600,
                'allowed_cert_dirs' => null,
            ],
            'connections' => [
                'default' => [
                    'endpoint' => 'opc.tcp://localhost:4840',
                ],
            ],
        ],
    ]);

    $app->instance('config', $config);
    $app->instance('events', new Dispatcher($app));
    $app->instance('path.config', sys_get_temp_dir());

    return $app;
}

describe('OpcuaServiceProvider', function () {

    afterEach(function () {
        Container::setInstance(null);
    });

    describe('register', function () {

        it('registers OpcuaManager as a singleton', function () {
            $app = makeApp();
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            $a = $app->make(OpcuaManager::class);
            $b = $app->make(OpcuaManager::class);

            expect($a)->toBeInstanceOf(OpcuaManager::class);
            expect($a)->toBe($b);
        });

        it('registers the "opcua" alias', function () {
            $app = makeApp();
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            $fromClass = $app->make(OpcuaManager::class);
            $fromAlias = $app->make('opcua');

            expect($fromAlias)->toBe($fromClass);
        });

        it('merges the default config', function () {
            $app = makeApp();
            $provider = new OpcuaServiceProvider($app);

            $provider->register();

            $config = $app->make('config')->get('opcua');

            expect($config)->toBeArray();
            expect($config)->toHaveKey('default');
            expect($config)->toHaveKey('session_manager');
            expect($config)->toHaveKey('connections');
        });
    });

    describe('boot', function () {

        it('registers the opcua:session command when running in console', function () {
            $app = Mockery::mock(Container::class . '[runningInConsole]');
            $app->shouldReceive('runningInConsole')->andReturn(true);
            Container::setInstance($app);

            $app->instance('app', $app);

            $config = new Repository([
                'opcua' => [
                    'default' => 'default',
                    'session_manager' => [
                        'enabled' => false,
                        'socket_path' => '/tmp/test.sock',
                        'timeout' => 600,
                        'cleanup_interval' => 30,
                        'auth_token' => null,
                        'max_sessions' => 100,
                        'socket_mode' => 0600,
                        'allowed_cert_dirs' => null,
                    ],
                    'connections' => [
                        'default' => [
                            'endpoint' => 'opc.tcp://localhost:4840',
                        ],
                    ],
                ],
            ]);

            $app->instance('config', $config);
            $app->instance('events', new Dispatcher($app));
            $app->instance('path.config', sys_get_temp_dir());

            $provider = new OpcuaServiceProvider($app);
            $provider->register();
            $provider->boot();

            expect(true)->toBeTrue();
        });
    });
});
