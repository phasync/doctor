<?php

// Mirrors Yii3-Benchmarks/app-api's config/common/di/db.php exactly: aliased `use` imports, a
// ConnectionInterface::class key, a nested ['class' => Connection::class, '__construct()' => [...]]
// value, and a persistent PDO buried inside the constructor arguments.

namespace Yiisoft\Db\Connection {
    interface ConnectionInterface
    {
    }
}

namespace Yiisoft\Db\Pgsql {
    class Connection implements \Yiisoft\Db\Connection\ConnectionInterface
    {
    }
}

namespace {
    use Yiisoft\Db\Connection\ConnectionInterface;
    use Yiisoft\Db\Pgsql\Connection;

    return [
        ConnectionInterface::class => [
            'class' => Connection::class,
            '__construct()' => [
                'driver' => new \PDO(
                    'pgsql:host=x',
                    'u',
                    'p',
                    [\PDO::ATTR_PERSISTENT => true]
                ),
            ],
        ],
    ];
}
