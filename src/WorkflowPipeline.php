<?php

namespace Splitstack\Compensable;

use Splitstack\Compensable\Contracts\Compensable;
use Splitstack\Compensable\Contracts\Undoable;

/**
 * A CompensableScope over steps. Wraps the whole sequence in a DB
 * transaction (inner UseCase transactions become savepoints), so the
 * Transactioner fully owns DB rollback. Compensation only replays
 * external mutations, in reverse, on every completed Undoable step.
 *
 * A step is either an invokable Steppable (typically adapting an
 * existing Action or UseCase — see the IsSteppable trait) or a bare
 * Compensable run through the scope directly. An invokable step's
 * undo() receives the passable.
 *
 * Designed to be extended by a named workflow:
 *
 *     final class AirbnbRoomPlanSyncWorkflow extends WorkflowPipeline
 *     {
 *         public function sync(RoomPlanId $id): void
 *         {
 *             $this->steps([$this->startSync, $this->updateListing])
 *                 ->skippable($this->syncLeadTime, fn($p) => $p->needsLeadTimeSync())
 *                 ->steps([$this->completeSync])
 *                 ->run(new AirbnbSyncPayload(...));
 *         }
 *     }
 *
 * (The entry point must not be named run() — overriding the inherited
 * run() with extra required parameters is an incompatible signature.)
 *
 * A step may throw WorkflowAbortedException for a graceful early exit:
 * completed work commits, no compensation, nothing rethrown.
 */
class WorkflowPipeline extends CompensableScope
{
    /** @var array{pipes: array<callable|Compensable>, when: callable|bool}[] */
    private array $stages = [];

    /**
     * Append unconditional steps. May be called multiple times.
     *
     * @param  array<callable|Compensable>  $steps
     */
    public function steps(array $steps): static
    {
        $this->stages[] = ['pipes' => $steps, 'when' => true];

        return $this;
    }

    /**
     * Append steps that only run when $when holds. A callable predicate
     * receives the passable and is evaluated lazily at run() time.
     *
     * @param  callable|Compensable|array<callable|Compensable>  $steps
     */
    public function skippable(callable|Compensable|array $steps, callable|bool $when): static
    {
        $this->stages[] = ['pipes' => \is_array($steps) ? $steps : [$steps], 'when' => $when];

        return $this;
    }

    public function run(mixed $passable): mixed
    {
        return $this->execute(function () use ($passable): mixed {
            try {
                foreach ($this->pipes($passable) as $pipe) {
                    // a self-skipping step (unsatisfied requires()) never
                    // runs, so it is never tracked for compensation either
                    if (\is_object($pipe) && method_exists($pipe, 'skips') && $pipe->skips($passable)) {
                        continue;
                    }

                    if (\is_callable($pipe)) {
                        $pipe($passable, null);

                        if ($pipe instanceof Undoable) {
                            $this->track($pipe, $passable);
                        }
                    } else {
                        $this->step($pipe, $passable);
                    }
                }
            } catch (WorkflowAbortedException) {
                // caught inside the transaction closure on purpose:
                // completed work commits and no compensation runs
            }

            return $passable;
        });
    }

    /**
     * @return array<callable|Compensable>
     */
    private function pipes(mixed $passable): array
    {
        $pipes = [];

        foreach ($this->stages as $stage) {
            $when = $stage['when'];

            if ($when === true || (\is_callable($when) && $when($passable))) {
                array_push($pipes, ...$stage['pipes']);
            }
        }

        return $pipes;
    }
}
