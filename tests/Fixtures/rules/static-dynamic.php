<?php

class D
{
    public static $x = 1;

    public static function run(string $class, string $name): void
    {
        echo $class::$x;
        echo D::$$name;
        $class::$x = 2;
    }
}
