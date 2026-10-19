<?php

function current_locale(): string
{
    return setlocale(LC_ALL, '0');
}
