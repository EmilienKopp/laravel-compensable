<?php

namespace Splitstack\Conveyor\Concerns;

use Splitstack\Conveyor\Contracts\IsDomainEvent;

trait RecordsEvents
{
    /** @var list<IsDomainEvent> */
    private array $recordedEvents = [];

    public function recordEvent(IsDomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /** @return list<IsDomainEvent> */
    public function getRecordedEvents(): array
    {
        return $this->recordedEvents;
    }

    public function clearEvents(): void
    {
        $this->recordedEvents = [];
    }
}
