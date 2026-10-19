<?php

namespace Acme\Tests;

final class ClockTest
{
    private static int $runs = 0;

    public function testFreeze(): void
    {
        ++self::$runs;
    }
}
