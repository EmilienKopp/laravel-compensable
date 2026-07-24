<?php

namespace Splitstack\Compensable\Contracts;

use Splitstack\Compensable\RetryConfig;

/**
 * Atomic unit of application work. One thing, no transaction of its own,
 * no orchestration — it participates in the caller's scope.
 *
 * undo() compensates EXTERNAL mutations only (API calls, S3 writes, ...);
 * DB writes are reverted by the owning scope's transaction, never by undo().
 * Declaring undo() is mandatory even when the answer is "nothing to undo"
 * (a no-op body): the point of no return must be explicit, decided at
 * definition time rather than discovered at incident time.
 *
 * @template T
 *
 * @implements Compensable<T>
 */
abstract class Action implements Compensable
{
    /**
     * @return T
     */
    abstract public function handle(...$args): mixed;

    /**
     * @param  T  $result
     */
    abstract public function undo(mixed $result = null): void;

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
