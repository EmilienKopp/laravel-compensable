<?php

namespace Splitstack\Conveyor;

/**
 * Retry policy for a Compensable. Returned by getRetryConfig() — null
 * means no retry. When non-null, isUnrecoverableError() MUST be
 * implemented on the same class or a BadMethodCallException is thrown.
 *
 * timeoutSeconds is advisory: in-process retries use retryAfterSeconds
 * as a sleep interval; queue-backed jobs should use retryAfterSeconds
 * as the job's retryAfter / backoff value and timeoutSeconds as the
 * job timeout.
 */
final readonly class RetryConfig
{
    public function __construct(
        public int $tries = 3,
        public int $retryAfterSeconds = 1,
        public int $timeoutSeconds = 14,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public static function make(int $tries, int $retryAfterSeconds = 1, int $timeoutSeconds = 14): self
    {
        return new self($tries, $retryAfterSeconds, $timeoutSeconds);
    }
}
