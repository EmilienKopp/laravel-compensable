<?php

namespace Splitstack\Conveyor;

use Closure;
use Illuminate\Pipeline\Pipeline;
use Splitstack\Conveyor\Concerns\ManagesUndoStack;
use Splitstack\Conveyor\Contracts\Undoable;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;

class WorkflowPipeline
{
    use ManagesUndoStack;

    /** @var array{pipes: array<callable>, when: callable|bool}[] */
    private array $stages = [];

    public function __construct(private readonly Transactioner $transactioner) {}

    /** @param array<callable> $steps */
    public function steps(array $steps): static
    {
        $this->stages[] = ['pipes' => $steps, 'when' => true];

        return $this;
    }

    /** @param callable|array<callable> $steps */
    public function skippable(callable|array $steps, callable|bool $when): static
    {
        $this->stages[] = ['pipes' => \is_array($steps) ? $steps : [$steps], 'when' => $when];

        return $this;
    }

    public function run(mixed $passable): mixed
    {
        $stages = $this->stages;
        $this->stages = [];
        $this->resetUndoStack();

        try {
            return $this->transactioner->execute(function () use ($passable, $stages): mixed {
                $wrapped = array_map(
                    fn (mixed $pipe) => $this->wrap($pipe),
                    $this->resolvePipes($stages, $passable),
                );

                try {
                    (new Pipeline(app()))->send($passable)->through($wrapped)->thenReturn();
                } catch (WorkflowAbortedException) {
                    // graceful early exit — completed work commits, no compensation
                }

                return $passable;
            });
        } catch (\Throwable $e) {
            $this->compensate($e);
            throw $e;
        }
    }

    private function wrap(mixed $pipe): Closure
    {
        return function (mixed $payload, Closure $next) use ($pipe): mixed {
            if (\is_object($pipe) && method_exists($pipe, 'skips') && $pipe->skips($payload)) {
                return $next($payload);
            }

            $pipe($payload, null);

            if ($pipe instanceof Undoable) {
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
