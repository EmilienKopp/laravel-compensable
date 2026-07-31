<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Actions;

use Splitstack\Conveyor\Contracts\Action;
use Splitstack\Conveyor\Data\RetryConfig;

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

    public function rewind(mixed $result = null): void {}

    public function getRetryConfig(): ?RetryConfig
    {
        return RetryConfig::make(tries: 3, backoff: 0);
    }

    public function isUnrecoverableError(\Throwable $e): bool
    {
        return $this->unrecoverableOnSecondFailure && $this->calls >= 2;
    }
}
