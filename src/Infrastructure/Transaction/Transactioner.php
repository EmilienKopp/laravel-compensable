<?php

namespace Splitstack\Compensable\Infrastructure\Transaction;

use Closure;
use Illuminate\Support\Facades\DB;
use Splitstack\Compensable\Infrastructure\Concerns\RunsWithEvents;

class Transactioner
{
    use RunsWithEvents;

    private ?Closure $onThrow = null;

    public function onThrow(Closure $callback): self
    {
        $this->onThrow = $callback;

        return $this;
    }

    public function execute(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (\Throwable $e) {
            if ($this->onThrow !== null) {
                ($this->onThrow)($e);
            }
            throw $e;
        }
    }

    public function executeWithEvents(Closure $callback, ?callable $dispatcher = null): mixed
    {
        try {
            $eventAwareObject = DB::transaction(fn () => $this->withEvents($callback, $dispatcher));
            DB::afterCommit($eventAwareObject->emitter);

            return $eventAwareObject->result;
        } catch (\Throwable $e) {
            if ($this->onThrow !== null) {
                ($this->onThrow)($e);
            }
            throw $e;
        }
    }
}
