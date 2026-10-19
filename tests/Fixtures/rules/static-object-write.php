<?php

class O
{
    public static $registry;
    public static $config;

    public static function f(): void
    {
        self::$registry->items[] = 1;
        echo self::$config->name;
    }
}
