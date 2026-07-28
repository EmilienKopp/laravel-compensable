<?php

namespace Splitstack\Conveyor\Contracts;

use Closure;

interface TransactsWithEvents
{
    public function execute(Closure $callback, ?Closure $onError = null): mixed;

    public function executeWithEvents(Closure $callback, ?Closure $onError = null, ?callable $dispatcher = null): mixed;
}
