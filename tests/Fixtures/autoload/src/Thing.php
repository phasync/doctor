<?php

namespace App;

use Lib\Base;

class Thing extends Base
{
    public static function add(): void
    {
        self::$registry[] = 1;
        static::$registry[] = 2;
    }
}
