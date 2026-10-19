<?php

trait HasDispatcher
{
    public static function setDispatcher($d): void
    {
        static::$dispatcher = $d;
    }
}

class Model
{
    use HasDispatcher;

    protected static $dispatcher;
}
