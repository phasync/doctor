<?php

namespace Inherit;

class Child extends Base
{
    protected static int $own = 1;

    public static function run(): void
    {
        static::$shared['a'] = 1;
        self::$shared['b'] = 2;
        Child::$shared['c'] = 3;
        parent::$shared['d'] = 4;
        self::$own = 5;
    }
}
