<?php

namespace Acme;

final class Clock
{
    public static ?int $now = null;

    public static function freeze(int $at): void
    {
        self::$now = $at;
    }
}
