<?php

namespace Splitstack\Conveyor;

use Closure;
use Illuminate\Support\Facades\Log;
use Splitstack\Conveyor\Concerns\ManagesRewindStack;
use Splitstack\Conveyor\Contracts\TransactionalUnit;
use Splitstack\Conveyor\Data\FailedStep;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;

class TransactionalBoundary
{
    use ManagesRewindStack;

    private ?Closure $onStepFailed = null;

    public function __construct(private readonly Transactioner $transactioner) {}

    /**
     * @param Closure(FailedStep): void $callback
     */
    public function onStepFailed(Closure $callback): static
    {
        $this->onStepFailed = $callback;

        return $this;
    }

    public function execute(Closure $callback, ?Closure $onError = null): mixed
    {
        if ($onError !== null) {
            $this->transactioner->onThrow($onError);
        }

        try {
            return $this->transactioner->execute(fn() => $callback($this));
        } catch (\Throwable $e) {
            // Inside this boundary's own transaction: the rollback reverted DB
            // writes, so only external effects need compensating.
            $this->compensate(cause: $e);
            throw $e;
        }
    }

    public function executeWithEvents(Closure $callback, ?Closure $onError = null, ?callable $dispatcher = null): mixed
    {
        if ($onError !== null) {
            $this->transactioner->onThrow($onError);
        }

        try {
            return $this->transactioner->executeWithEvents(fn() => $callback($this), $dispatcher);
        } catch (\Throwable $e) {
            $this->compensate(cause: $e);
            throw $e;
        }
    }

    public function step(TransactionalUnit $action, mixed ...$args): mixed
    {
        $result = $this->attempt($action, $args);
        $this->track($action, $result);

        return $result;
    }

    /** @param array<mixed> $args */
    private function attempt(TransactionalUnit $action, array $args): mixed
    {
        $config = $action->getRetryConfig();

        if ($config === null) {
            return $action->handle(...$args);
        }

        $attempts = 0;
        $last = null;

        while ($attempts < $config->tries) {
            try {
                return $action->handle(...$args);
            } catch (\Throwable $e) {
                $last = $e;
                $attempts++;

                if ($action->isUnrecoverableError($e) || $attempts >= $config->tries) {
                    break;
                }

                sleep($config->backoff);
            }
        }

        $this->reportStepFailure(new FailedStep($action, $last, $attempts, $config));

        throw $last;
    }

    private function reportStepFailure(FailedStep $failure): void
    {
        if ($this->onStepFailed !== null) {
            ($this->onStepFailed)($failure);

            return;
        }

        Log::error('Step failed after retries', [
            'action'    => $failure->action::class,
            'attempts'  => $failure->attempts,
            'exception' => $failure->exception,
            'failed_at' => $failure->failedAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
