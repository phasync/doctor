<?php

namespace Phasync\Doctor;

enum Severity: string
{
    case Error   = 'error';
    case Warning = 'warning';
    case Info    = 'info';

    public function rank(): int
    {
        return match ($this) {
            self::Error   => 0,
            self::Warning => 1,
            self::Info    => 2,
        };
    }
}
