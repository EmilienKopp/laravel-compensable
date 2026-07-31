<?php

namespace Splitstack\Conveyor;

use Closure;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Bus;
use Splitstack\Conveyor\Concerns\ManagesRewindStack;
use Splitstack\Conveyor\Contracts\Skippable;
use Splitstack\Conveyor\Contracts\Rewindable;
use Splitstack\Conveyor\Contracts\Steppable;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;

class WorkflowPipeline
{
    use ManagesRewindStack;

    /** @var array{pipes: array<callable>, when: callable|bool, delegated?: bool, afterCommit?: bool}[] */
    private array $stages = [];

    private bool $runInTransaction = false;

    public function __construct(private readonly Transactioner $transactioner) {}

    /** @param array<callable|Steppable> $steps */
    public function steps(array $steps): static
    {
        $this->stages[] = ['pipes' => $steps, 'when' => true];

        return $this;
    }

    /** @param Closure|Steppable|array<Closure|Steppable> $steps */
    public function skippable(callable|array $steps, callable|bool $when): static
    {
        $this->stages[] = ['pipes' => \is_array($steps) ? $steps : [$steps], 'when' => $when];

        return $this;
    }

    /**
     * Delegate a step to the queue instead of running it inline. The step
     * is dispatched (never invoked here), so it is not tracked on the
     * rewind stack — it compensates itself via failed() → rewind().
     *
     * When $afterCommit is true (the default), dispatch waits for the
     * surrounding transaction to commit, so inline / transacts() writes
     * land before the worker reads them. Pass false to dispatch
     * immediately — the developer's call.
     *
     * Multiple delegated steps form a single chain in declaration order.
     */
    public function delegate(Steppable $step, bool $afterCommit = true, ?callable $when = null): static
    {
        $this->stages[] = [
            'pipes'       => [$step],
            'when'        => $when ?? true,
            'delegated'   => true,
            'afterCommit' => $afterCommit,
        ];

        return $this;
    }

    public function transacts(): static
    {
        $this->runInTransaction = true;

        return $this;
    }

    public function run(mixed $passable): mixed
    {
        try {
            if ($this->runInTransaction) {
                // compensate() sits outside execute() on purpose: the transaction
                // rolls back first, then rewind() runs, so a compensating write (e.g.
                // a Failed status) lands after rollback and survives.
                return $this->transactioner->execute(fn () => $this->convey($passable));
            }

            return $this->convey($passable);
        } catch (\Throwable $e) {
            $this->compensate($e);
            throw $e;
        }
    }

    private function convey(mixed $passable): mixed
    {
        $stages = $this->stages;
        $this->stages = [];
        $this->resetRewindStack();

        $inline = array_filter($stages, static fn (array $s): bool => empty($s['delegated']));
        $delegated = array_filter($stages, static fn (array $s): bool => ! empty($s['delegated']));

        $wrapped = array_map(
            fn (mixed $pipe) => $this->wrap($pipe),
            $this->resolvePipes($inline, $passable),
        );

        try {
            (new Pipeline(app()))->send($passable)->through($wrapped)->thenReturn();
        } catch (WorkflowAbortedException) {
            // graceful early exit — completed work commits, no compensation,
            // and the delegated tail is never dispatched
            return $passable;
        }

        $this->dispatchDelegated($delegated, $passable);

        return $passable;
    }

    /** @param array{pipes: array<callable>, when: callable|bool, afterCommit: bool}[] $stages */
    private function dispatchDelegated(array $stages, mixed $passable): void
    {
        $jobs = [];
        $afterCommit = true;

        foreach ($stages as $stage) {
            $when = $stage['when'];

            if ($when !== true && ! (\is_callable($when) && $when($passable))) {
                continue;
            }

            foreach ($stage['pipes'] as $step) {
                if ($step instanceof Skippable && $step->skips($passable)) {
                    continue;
                }

                $jobs[] = ConveyorStepJob::for($step, $passable);
                $afterCommit = $afterCommit && $stage['afterCommit'];
            }
        }

        if ($jobs === []) {
            return;
        }

        // afterCommit is a chain-level concern: the head job's flag governs
        // dispatch timing. Wait for commit unless any delegate() opted out.
        if ($afterCommit) {
            $jobs[0]->afterCommit();
        }

        Bus::chain($jobs)->dispatch();
    }

    private function wrap(mixed $pipe): Closure
    {
        return function (mixed $payload, Closure $next) use ($pipe): mixed {
            if ($pipe instanceof Skippable && $pipe->skips($payload)) {
                return $next($payload);
            }

            $pipe($payload, null);

            if ($pipe instanceof Rewindable) {
                $this->track($pipe, $payload);
            }

            return $next($payload);
        };
    }

    /** @return array<callable> */
    private function resolvePipes(array $stages, mixed $passable): array
    {
        $pipes = [];

        foreach ($stages as $stage) {
            $when = $stage['when'];

            if ($when === true || (\is_callable($when) && $when($passable))) {
                array_push($pipes, ...$stage['pipes']);
            }
        }

        return $pipes;
    }
}
