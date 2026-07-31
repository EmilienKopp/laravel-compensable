<?php

namespace Splitstack\Conveyor\Contracts;

use Splitstack\Conveyor\Data\RetryConfig;

/**
 * @template T
 */
interface TransactionalUnit extends Rewindable
{
    /**
     * Perform a process
     *
     * @return T
     */
    public function handle(...$args): mixed;

    /**
     * Opt-in retry policy. Return null for no retry (default).
     * When non-null, isUnrecoverableError() MUST be implemented.
     */
    public function getRetryConfig(): ?RetryConfig;

    /**
     * Determine whether an exception is unrecoverable (no retry).
     * Only called when getRetryConfig() is non-null — throw
     * BadMethodCallException in the default impl to force authors to
     * answer the question when they opt into retry.
     */
    public function isUnrecoverableError(\Throwable $e): bool;
}
