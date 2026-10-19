<?php

set_error_handler(fn () => false);
function a() { set_exception_handler(fn () => null); }
class H
{
    public function m(): void
    {
        register_shutdown_function(fn () => null);
        error_log('x');
    }
}
$f = function () { spl_autoload_register(fn () => null); };
function temp()
{
    set_error_handler(fn () => true);
    restore_error_handler();
}
