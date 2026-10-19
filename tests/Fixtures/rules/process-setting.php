<?php

ini_set('memory_limit', '1G');
setlocale(LC_ALL, 'C');
mb_internal_encoding();
mb_internal_encoding('UTF-8');
error_reporting();
error_reporting(0);
set_time_limit(5);
ignore_user_abort();
ignore_user_abort(true);
putenv('A=1');
$o->ini_set('x', 'y');
\chdir('/');
date_default_timezone_set('UTC');
Locale::setDefault('nb');
$_GET['a'] = 1;
$_POST = [];
$_SERVER['X'] = 1;
$_ENV['Y'] = 2;
umask(0);
set_include_path('.');
echo setlocale(LC_ALL, 0);
$_REQUEST['x'] = 1;
$_COOKIE['c'] = 1;
$_FILES = [];
echo $_SERVER['HTTP_HOST'];
