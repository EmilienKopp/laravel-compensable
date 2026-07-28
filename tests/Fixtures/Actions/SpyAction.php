<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Actions;

use Splitstack\Conveyor\Contracts\Action;

/**
 * Records handle/undo calls into a shared log so tests can assert
 * execution and compensation order.
 *
 * @extends Action<string>
 */
class SpyAction extends Action
{
    public function __construct(
        private readonly string $name,
        private readonly \ArrayObject $log,
        private readonly bool $failOnHandle = false,
        private readonly bool $failOnUndo = false,
    ) {}

    public function handle(...$args): string
    {
        if ($this->failOnHandle) {
            throw new \RuntimeException("{$this->name} failed");
        }

        $this->log[] = "handle:{$this->name}";

        return $this->name;
    }

    public function undo(mixed $result = null): void
    {
        if ($this->failOnUndo) {
            throw new \RuntimeException("undo of {$this->name} failed");
        }

        $this->log[] = "undo:{$result}";
    }
}
