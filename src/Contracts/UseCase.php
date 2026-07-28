<?php

namespace Splitstack\Conveyor\Contracts;

use Splitstack\Conveyor\TransactionalBoundary;
use Splitstack\Conveyor\RetryConfig;

/**
 * A UseCase is a TransactionalBoundary over its Actions, and is itself
 * Compensable so a parent scope (e.g. a WorkflowPipeline) can undo it —
 * cascading compensation down to every Action it ran.
 */
abstract class UseCase extends TransactionalBoundary implements TransactionalUnit, TransactsWithEvents
{
    abstract public function handle(...$args): mixed;

    public function undo(mixed $result = null): void
    {
        $this->compensate();
    }

    public function getRetryConfig(): ?RetryConfig
    {
        return null;
    }

    public function isUnrecoverableError(\Throwable $e): bool
    {
        throw new \BadMethodCallException(
            static::class . '::isUnrecoverableError() must be implemented when getRetryConfig() returns non-null.'
        );
    }
}
