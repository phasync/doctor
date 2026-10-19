<?php

trait Hits
{
    public static int $hits = 0;

    public static function hit(): void
    {
        static::$hits++;
    }
}

class One
{
    use Hits;
}

class Two
{
    use Hits;
}
