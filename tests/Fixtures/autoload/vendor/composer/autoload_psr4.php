<?php

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return [
    'Lib\\' => [$vendorDir . '/lib'],
    'App\\' => [$baseDir . '/src'],
];
