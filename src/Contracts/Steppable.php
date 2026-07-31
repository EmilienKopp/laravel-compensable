<?php

namespace Splitstack\Conveyor\Contracts;

/**
 * A pipeline-aware step. Use IsSteppable to satisfy __invoke().
 * You need to manually implement Undoable if the step mutates external state.
 */
interface Steppable extends Skippable
{
    public function __invoke(mixed $passable, ?\Closure $next = null): mixed;
}