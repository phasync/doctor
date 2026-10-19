<?php

function a() { static $n = 0; ++$n; }
function b() { static $k = 5; return $k; }
function c() { static $m; if ($m === null) { $m = 1; } }
function d() { static $cache = []; $cache[1] = 2; }
function e() { static $arr = ['a' => 1]; return $arr; }
function f() { static $now = null; return $now ??= time(); }
$g = static fn () => 1;
$h = static function () { return 1; };
class K
{
    public static function m()
    {
        static::x();
        static $z = 1;

        return $z;
    }
}
function g()
{
    /** @scope-safe memo of a pure function */
    static $memo = [];
    $memo[1] = 1;
}
function h()
{
    // @scope-safe compiled patterns, the same for every request
    static $patterns = [];
    $patterns[] = 1;
}
