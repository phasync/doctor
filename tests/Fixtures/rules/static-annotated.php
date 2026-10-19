<?php

class A
{
    /** @scope-safe compiled routes, the same for every request */
    public static array $routes = [];
    /** @scope-safe */
    public static $bare;
    public static $plain = 0;

    public static function f(): void
    {
        self::$routes[] = 1;
        self::$bare = 1;
        ++self::$plain;
    }
}

/** @scope-safe registry filled at boot */
class B
{
    public static $x;

    public static function f(): void
    {
        self::$x = 1;
        static $c = 0;
        ++$c;
    }
}
