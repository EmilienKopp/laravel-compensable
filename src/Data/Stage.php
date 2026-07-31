<?php

declare(strict_types=1);

namespace Splitstack\Conveyor\Data;

use Closure;

final readonly class Stage
{
    public function __construct(
        public array $steps,
        public bool|Closure $when,
        public bool $delegated = false,
        public bool $afterCommit = true,
    ) {}
}