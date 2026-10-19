<?php

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

namespace Vendor\Logging {
    class Logger
    {
    }
}

namespace App {
    $definitions = [
        \Yiisoft\Db\Connection\ConnectionInterface::class => \Yiisoft\Db\Pgsql\Connection::class,
        'cache' => ['class' => \Yiisoft\Db\Pgsql\Connection::class, '__construct()' => ['dsn' => 'pgsql:...']],
        'logger' => \Vendor\Logging\Logger::class,
    ];

    $app->bind('db', fn () => new \PDO('mysql:host=x'), true);
    $app->bind('session', fn () => new \PDO('mysql:host=y'));
}
