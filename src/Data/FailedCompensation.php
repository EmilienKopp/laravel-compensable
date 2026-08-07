<?php

namespace Splitstack\Conveyor\Data;

use Splitstack\Conveyor\Contracts\CompensatesData;
use Splitstack\Conveyor\Contracts\Rewindable;

/**
 * Full context of a failed compensation (rewind() or compensateData()),
 * handed to the onCompensationFailed hook.
 */
final class FailedCompensation
{
    public readonly \DateTimeImmutable $failedAt;

    public function __construct(
        public readonly Rewindable|CompensatesData $action,
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
        if ($this->action instanceof Rewindable) {
            $this->action->rewind($this->result);
        }

        if ($this->action instanceof CompensatesData) {
            $this->action->compensateData();
        }
    }
}
