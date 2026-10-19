<?php

class Db
{
    private static ?PDO $pdo = null;
    public static function get(): PDO { return self::$pdo ??= new PDO('sqlite::memory:'); }
}
function db() { static $m; return $m ??= new mysqli(); }
function g() { global $redis; $redis = new Redis(); }
$GLOBALS['mc'] = new Memcached();
$container->singleton('db', fn () => new PDO('x'));
function perRequest() { $pdo = new PDO('z'); return $pdo; }
$app->bind('x', fn () => new PDO('w'));
return [PDO::class => function () { return new PDO('y'); }];
