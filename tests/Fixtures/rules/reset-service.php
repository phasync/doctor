<?php

namespace App;

use Symfony\Contracts\Service\ResetInterface;

final class Cart implements ResetInterface
{
    public array $items = [];

    public function reset(): void
    {
        $this->items = [];
    }
}

return [
    CurrentRoute::class => [
        'reset' => function () {
            $this->route = null;
        },
    ],
    Other::class => ['class' => Other::class],
];
