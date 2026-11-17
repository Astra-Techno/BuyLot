<?php

declare(strict_types=1);

namespace App\Core\Event;

interface EventInterface
{
    public function getName(): string;
}
