<?php

declare(strict_types=1);

namespace Splitstack\Compensable\Infrastructure\Concerns;

use Closure;

class EventAwareResult
{
    public Closure $emitter;

    public object $result;

    public function __construct(object $result, Closure $emitter)
    {
        $this->result = $result;
        $this->emitter = $emitter;
    }


}
