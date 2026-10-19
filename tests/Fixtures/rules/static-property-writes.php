<?php

class W
{
    public static $assign;
    public static $inc = 0;
    public static $dim = [];
    public static $ref = [];
    public static $each = [];
    public static $alias = [];
    public static $unset = [];
    public static $read = 1;
    public static $compound = '';
    public static $list;
    public const LIMIT = 5;
    public int $instance = 0;

    public function run(): void
    {
        self::$assign = 1;
        --static::$inc;
        W::$dim['a']['b'][] = 1;
        sort(self::$ref);
        foreach (self::$each as &$v) {
        }
        $x = &self::$alias;
        unset(self::$unset['k']);
        self::$compound .= 'x';
        [self::$list] = [1];
        echo self::$read, self::LIMIT, count(self::$ref);
        self::$inc += 2;
        $this->instance = 2;
    }
}
