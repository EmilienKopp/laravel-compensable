<?php

namespace Splitstack\Conveyor\Concerns;

use Closure;
use Illuminate\Support\Facades\Log;
use Splitstack\Conveyor\Data\FailedCompensation;
use Splitstack\Conveyor\Contracts\CompensatesData;
use Splitstack\Conveyor\Contracts\Rewindable;

trait ManagesRewindStack
{
    /** @var array{0: Rewindable|CompensatesData, 1: mixed}[] */
    private array $rewindStack = [];

    private ?Closure $onCompensationFailed = null;

    /** @param Closure(FailedCompensation): void $callback */
    public function onCompensationFailed(Closure $callback): static
    {
        $this->onCompensationFailed = $callback;

        return $this;
    }

    protected function track(Rewindable|CompensatesData $action, mixed $result = null): void
    {
        $this->rewindStack[] = [$action, $result];
    }

    /**
     * Run compensation in reverse order.
     *
     * rewind() (external effects) always runs. compensateData() (committed DB
     * writes) runs only when $compensateData is true — i.e. the owning scope
     * did NOT transact, so no rollback reverted those writes.
     */
    public function compensate(bool $compensateData = false, ?\Throwable $cause = null): void
    {
        foreach (array_reverse($this->rewindStack) as [$action, $result]) {
            try {
                if ($action instanceof Rewindable) {
                    $action->rewind($result);
                }

                if ($compensateData && $action instanceof CompensatesData) {
                    $action->compensateData();
                }
            } catch (\Throwable $e) {
                $this->reportCompensationFailure(new FailedCompensation($action, $result, $e, $cause));
            }
        }

        $this->resetRewindStack();
    }

    protected function resetRewindStack(): void
    {
        $this->rewindStack = [];
    }

    private function reportCompensationFailure(FailedCompensation $failure): void
    {
        if ($this->onCompensationFailed !== null) {
            ($this->onCompensationFailed)($failure);

            return;
        }

        Log::error('Compensation failed', [
            'action'    => $failure->action::class,
            'exception' => $failure->exception,
            'cause'     => $failure->cause?->getMessage(),
            'failed_at' => $failure->failedAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
