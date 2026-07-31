<?php

namespace Splitstack\Conveyor\Concerns;

use Closure;
use Splitstack\Conveyor\Contracts\SequencePayload;

/**
 * Wires __invoke() to handle() or execute() so any class can be used as
 * a sequence step. The passable flows through unchanged — steps produce
 * side effects only. If the step defines requires(): array and the
 * passable is a SequencePayload, the step self-skips when its required
 * keys are not present in the payload.
 */
trait IsSteppable
{
    public function __invoke(mixed $passable, ?Closure $next = null): mixed
    {
        if (! $this->skips($passable)) {
            match (true) {
                method_exists($this, 'handle') => $this->handle($passable),
                method_exists($this, 'execute') => $this->execute($passable),
                default => null,
            };
        }

        return $next !== null ? $next($passable) : $passable;
    }

    public function skips(mixed $passable): bool
    {
        if (! method_exists($this, 'requires') || ! $passable instanceof SequencePayload) {
            return false;
        }

        foreach ($this->requires() as $key) {
            if (! $passable->has($key)) {
                return true;
            }
        }

        return false;
    }
}
