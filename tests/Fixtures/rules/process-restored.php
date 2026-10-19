<?php

function xml($s) { $old = libxml_use_internal_errors(true); $r = simplexml_load_string($s); libxml_use_internal_errors($old); return $r; }
function enc() { $e = mb_internal_encoding(); mb_internal_encoding('UTF-8'); mb_internal_encoding($e); }
function mem() { ini_set('memory_limit', '1G'); }
function env() { putenv('A=1'); putenv('B=2'); }
