<?php

namespace Splitstack\Conveyor\Infrastructure\Concerns;

use Closure;
use Illuminate\Support\Facades\Event;
use Splitstack\Conveyor\Domain\Contracts\HasDomainEvents;
use Splitstack\Conveyor\Domain\Contracts\IsDomainEvent;

trait RunsWithEvents
{
    /**
     * @param  Closure(): HasDomainEvents  $callback
     */
    public function withEvents(Closure $callback, ?callable $dispatcher = null): EventAwareResult
    {
        $domainObject = $callback();

        return new EventAwareResult($domainObject, function () use ($domainObject, $dispatcher): void {
            $this->emitEvents($domainObject, $dispatcher);
        });
    }

    private function emitEvents(HasDomainEvents $domainObject, ?callable $dispatcher = null): void
    {
        foreach ($domainObject->getRecordedEvents() as $event) {
            $this->doDispatch($event, $dispatcher);
        }

        $domainObject->clearEvents();
    }

    private function doDispatch(IsDomainEvent $event, ?callable $dispatcher = null): void
    {
        if ($dispatcher !== null) {
            $dispatcher($event);
        } else {
            Event::dispatch($event);
        }
    }
}
