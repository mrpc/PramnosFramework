<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

/**
 * The same store on MySQL, where `authserver.x` is the prefixed table `authserver_x`.
 */
class PermissionsAuthserverStoreMySQLTest extends PermissionsAuthserverStoreTest
{
    protected function connectionSettings(): array
    {
        return [
            'type' => 'mysql', 'server' => 'db', 'port' => 3306,
            'user' => 'root', 'password' => 'secret', 'database' => TEST_DATABASE,
        ];
    }
}

