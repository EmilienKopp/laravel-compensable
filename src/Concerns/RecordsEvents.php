<?php

namespace Splitstack\Conveyor\Concerns;

use Splitstack\Conveyor\Contracts\IsDomainEvent;

/**
 * Default implementation of {@see \Splitstack\Conveyor\Contracts\HasDomainEvents}.
 *
 * Mix this into any domain entity to buffer events until the owning
 * transaction commits. The package only prescribes the IsDomainEvent
 * contract; the entity decides its own concrete event type via
 * {@see self::makeDomainEvent()}.
 */
trait RecordsEvents
{
    /** @var list<IsDomainEvent> */
    private array $recordedEvents = [];

    public function recordEvent(string $event, mixed $payload): void
    {
        $this->recordedEvents[] = $this->makeDomainEvent($event, $payload);
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

    /**
     * Build the concrete domain event for this entity.
     */
    abstract protected function makeDomainEvent(string $event, mixed $payload): IsDomainEvent;
}
