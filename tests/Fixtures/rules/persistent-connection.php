<?php

function attrTrue()
{
    return new PDO('mysql:host=x', 'u', 'p', [PDO::ATTR_PERSISTENT => true]);
}

function attrKey()
{
    return new PDO('mysql:host=x', 'u', 'p', [PDO::ATTR_PERSISTENT => 'pool1']);
}

function attrDynamic($key)
{
    return new PDO('mysql:host=x', 'u', 'p', [PDO::ATTR_PERSISTENT => $key]);
}

function setAttrTrue()
{
    $pdo = new PDO('mysql:host=x', 'u', 'p');
    $pdo->setAttribute(PDO::ATTR_PERSISTENT, true);

    return $pdo;
}

function attrFalse()
{
    return new PDO('mysql:host=x', 'u', 'p', [PDO::ATTR_PERSISTENT => false]);
}

function getAttr(PDO $pdo)
{
    return $pdo->getAttribute(PDO::ATTR_PERSISTENT);
}

function bareReference()
{
    $flag = PDO::ATTR_PERSISTENT;

    return $flag;
}

function noAttr()
{
    return new PDO('mysql:host=x', 'u', 'p', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function pgPersistent()
{
    return pg_pconnect('host=x');
}

function pgNormal()
{
    return pg_connect('host=x');
}

function mysqliNewPersistent()
{
    return new mysqli('p:host', 'u', 'p');
}

function mysqliNewNormal()
{
    return new mysqli('host', 'u', 'p');
}

function mysqliConnectPersistent()
{
    return mysqli_connect('p:host', 'u', 'p');
}

function mysqliRealConnectPersistent()
{
    $m = new mysqli();
    $m->real_connect('p:host', 'u', 'p');

    return $m;
}

function mysqliRealConnectNormal()
{
    $m = new mysqli();
    $m->real_connect('host', 'u', 'p');

    return $m;
}

function socketPersistent()
{
    return pfsockopen('host', 80);
}

function socketNormal()
{
    return fsockopen('host', 80);
}

function redisPersistent()
{
    $r = new Redis();
    $r->pconnect('host', 6379);

    return $r;
}

function redisNormal()
{
    $r = new Redis();
    $r->connect('host', 6379);

    return $r;
}

function memcachedPersistent()
{
    return new Memcached('pool1');
}

function memcachedNormal()
{
    return new Memcached();
}
