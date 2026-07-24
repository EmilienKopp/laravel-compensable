<?php

namespace Splitstack\Compensable\Tests\Fixtures\Actions;

use Splitstack\Compensable\Contracts\Action;
use Splitstack\Compensable\RetryConfig;

/**
 * Succeeds on the Nth attempt — simulates a transient external failure.
 *
 * @extends Action<string>
 */
class FlakeyAction extends Action
{
    private int $calls = 0;

    public function __construct(
        private readonly int $succeedOnAttempt = 2,
        private readonly bool $unrecoverableOnSecondFailure = false,
    ) {}

    public function handle(...$args): string
    {
        $this->calls++;

        if ($this->calls < $this->succeedOnAttempt) {
            throw new \RuntimeException("attempt {$this->calls} failed");
        }

        return "ok:{$this->calls}";
    }

    public function undo(mixed $result = null): void {}

    public function getRetryConfig(): ?RetryConfig
    {
        return RetryConfig::make(tries: 3, retryAfterSeconds: 0);
    }

    public function isUnrecoverableError(\Throwable $e): bool
    {
        return $this->unrecoverableOnSecondFailure && $this->calls >= 2;
    }
}
