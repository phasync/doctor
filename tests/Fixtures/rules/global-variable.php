<?php

function a() { global $x, $y; $x = 1; }
$GLOBALS['a'] = 1;
$v = $GLOBALS['a'];
$_SERVER['x'] = 1;
unset($GLOBALS['q']);
$GLOBALS['l'][] = 2;
function b() { global $y; return $y; }
function c()
{
    /** @scope-safe configuration loaded once at boot */
    global $config;
    $config = [];
}
function d() { global $x; return $x; }
