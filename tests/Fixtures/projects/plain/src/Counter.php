<?php

namespace Acme;

final class Counter
{
    private static int $hits = 0;

    public static function hit(): int
    {
        return ++self::$hits;
    }
}
