<?php

namespace Splitstack\Conveyor\Data;

use Splitstack\Conveyor\Contracts\Rewindable;

/**
 * Full context of a failed rewind(), handed to the onCompensationFailed hook.
 */
final class FailedCompensation
{
    public readonly \DateTimeImmutable $failedAt;

    public function __construct(
        public readonly Rewindable $action,
        public readonly mixed $result,
        public readonly \Throwable $exception,
        public readonly ?\Throwable $cause = null,
    ) {
        $this->failedAt = new \DateTimeImmutable;
    }

    /**
     * Re-attempt the failed compensation. Throws again on failure, so a
     * queued job's own tries/backoff can drive the retry policy.
     */
    public function retry(): void
    {
        $this->action->rewind($this->result);
    }
}
