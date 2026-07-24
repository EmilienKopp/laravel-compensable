<?php

namespace Splitstack\Compensable;

use Splitstack\Compensable\Contracts\Compensable;

/**
 * Context handed to the per-step onHandleFailed hook when all retries
 * are exhausted. Carries enough to dispatch a queued job:
 *
 *     ->onHandleFailed(fn(FailedStep $f) => RetryStepJob::dispatch($f->action, $f->retryConfig))
 *
 * The job's own tries / backoff / timeout properties map directly to
 * RetryConfig::tries / retryAfterSeconds / timeoutSeconds.
 */
final class FailedStep
{
    public readonly \DateTimeImmutable $failedAt;

    public function __construct(
        public readonly Compensable $action,
        public readonly \Throwable $exception,
        public readonly int $attempts,
        public readonly ?RetryConfig $retryConfig,
    ) {
        $this->failedAt = new \DateTimeImmutable();
    }
}
