<?php

namespace Splitstack\Conveyor\Concerns;

use Closure;
use Illuminate\Support\Facades\Log;
use Splitstack\Conveyor\FailedCompensation;
use Splitstack\Conveyor\Contracts\Undoable;

trait ManagesUndoStack
{
    /** @var array{0: Undoable, 1: mixed}[] */
    private array $undoStack = [];

    private ?Closure $onCompensationFailed = null;

    /** @param Closure(FailedCompensation): void $callback */
    public function onCompensationFailed(Closure $callback): static
    {
        $this->onCompensationFailed = $callback;

        return $this;
    }

    protected function track(Undoable $action, mixed $result = null): void
    {
        $this->undoStack[] = [$action, $result];
    }

    public function compensate(?\Throwable $cause = null): void
    {
        foreach (array_reverse($this->undoStack) as [$action, $result]) {
            try {
                $action->undo($result);
            } catch (\Throwable $e) {
                $this->reportCompensationFailure(new FailedCompensation($action, $result, $e, $cause));
            }
        }

        $this->resetUndoStack();
    }

    protected function resetUndoStack(): void
    {
        $this->undoStack = [];
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
