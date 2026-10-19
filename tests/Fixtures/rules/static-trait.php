<?php

trait Counts
{
    public static int $n = 0;
}

class Alpha
{
    use Counts;
}

class Beta
{
    use Counts;

    public static function reset(): void
    {
        self::$n = 0;
    }
}

class Gamma
{
    use Counts;
}
