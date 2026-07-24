<?php

namespace Splitstack\Compensable\Contracts;

/**
 * Marker interface for pipeline-aware steps. Implementing classes define
 * handle() or execute() with their specific passable type; __invoke() is
 * provided by the IsSteppable trait. A step wrapping something that
 * mutates external state should also implement Undoable — compensation
 * is then the STEP's declared behavior, letting existing Actions and
 * UseCases stay closed for modification.
 */
interface Steppable {}
