<?php

namespace Splitstack\Conveyor\Data;

/**
 * Retry policy for a Compensable. Returned by getRetryConfig() — null
 * means no retry. When non-null, isUnrecoverableError() MUST be
 * implemented on the same class or a BadMethodCallException is thrown.
 *
 * Wording matches Laravel's queued job properties: tries, backoff,
 * timeout. timeout is advisory: in-process retries use backoff as a
 * sleep interval; queue-backed jobs map backoff to the job's $backoff
 * and timeout to the job's $timeout.
 */
final readonly class RetryConfig
{
    public function __construct(
        public int $tries = 3,
        public int $backoff = 1,
        public int $timeout = 14,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public static function make(int $tries, int $backoff = 1, int $timeout = 14): self
    {
        return new self($tries, $backoff, $timeout);
    }
}
