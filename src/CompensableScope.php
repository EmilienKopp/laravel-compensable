<?php

namespace Splitstack\Compensable;

use Closure;
use Illuminate\Support\Facades\Log;
use Splitstack\Compensable\Contracts\Compensable;
use Splitstack\Compensable\Contracts\Undoable;
use Splitstack\Compensable\Infrastructure\Transaction\Transactioner;

class CompensableScope
{
    /** @var array{0: Undoable, 1: mixed}[] */
    private array $undoStack = [];

    private ?Closure $onCompensationFailed = null;

    private ?Closure $onStepFailed = null;

    public function __construct(private readonly Transactioner $transactioner) {}

    /**
     * @param Closure(FailedCompensation): void $callback
     */
    public function onCompensationFailed(Closure $callback): self
    {
        $this->onCompensationFailed = $callback;

        return $this;
    }

    /**
     * Called when a step exhausts all retries. Use this to dispatch a
     * queued job — FailedStep carries the action and RetryConfig so the
     * job's tries / retryAfter / timeout can map directly:
     *
     *     ->onStepFailed(fn(FailedStep $f) => RetryStepJob::dispatch($f->action, $f->retryConfig))
     *
     * @param Closure(FailedStep): void $callback
     */
    public function onStepFailed(Closure $callback): self
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
            $this->compensate($e);
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
            $this->compensate($e);
            throw $e;
        }
    }

    public function step(Compensable $action, mixed ...$args): mixed
    {
        $result = $this->attempt($action, $args);
        $this->track($action, $result);

        return $result;
    }

    /**
     * Register a completed unit for compensation without running it —
     * used by pipelines that invoke their steps directly.
     */
    protected function track(Undoable $action, mixed $result = null): void
    {
        $this->undoStack[] = [$action, $result];
    }

    /**
     * Walk the undo stack in reverse. A failing undo() never aborts the
     * cascade: the failure is reported (hook or log) and the remaining
     * compensations still run. Detection, not reaction.
     *
     * @param \Throwable|null $cause the exception that triggered compensation
     */
    public function compensate(?\Throwable $cause = null): void
    {
        foreach (array_reverse($this->undoStack) as [$action, $result]) {
            try {
                $action->undo($result);
            } catch (\Throwable $e) {
                $this->reportCompensationFailure(new FailedCompensation($action, $result, $e, $cause));
            }
        }

        $this->undoStack = [];
    }

    /**
     * Run handle() with retry if the action opts in via getRetryConfig().
     * On each transient failure: check isUnrecoverableError() — bail
     * immediately if true, otherwise sleep and retry. When retries are
     * exhausted the onStepFailed hook fires (e.g. dispatch a queue job)
     * and the last exception is rethrown to trigger compensation.
     *
     * @param array<mixed> $args
     */
    private function attempt(Compensable $action, array $args): mixed
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

                sleep($config->retryAfterSeconds);
            }
        }

        $this->reportStepFailure(new FailedStep($action, $last, $attempts, $config));

        throw $last;
    }

    private function reportCompensationFailure(FailedCompensation $failure): void
    {
        if ($this->onCompensationFailed !== null) {
            ($this->onCompensationFailed)($failure);

            return;
        }

        Log::error('Compensation failed', [
            'action'     => $failure->action::class,
            'exception'  => $failure->exception,
            'cause'      => $failure->cause?->getMessage(),
            'failed_at'  => $failure->failedAt->format(\DateTimeInterface::ATOM),
        ]);
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
