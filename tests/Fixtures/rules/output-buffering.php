<?php

namespace App;

ob_start();
\ob_get_clean();
flush();
echo 'x';
print 'y';
$o->ob_start();
Foo::flush();
Other\ob_end_clean();
