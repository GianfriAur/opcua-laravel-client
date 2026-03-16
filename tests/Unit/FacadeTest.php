<?php

declare(strict_types=1);

use Gianfriaur\OpcuaLaravel\Facades\Opcua;
use Gianfriaur\OpcuaLaravel\OpcuaManager;

describe('Opcua Facade', function () {

    it('resolves to OpcuaManager class', function () {
        $accessor = (new ReflectionMethod(Opcua::class, 'getFacadeAccessor'))->invoke(null);

        expect($accessor)->toBe(OpcuaManager::class);
    });
});
