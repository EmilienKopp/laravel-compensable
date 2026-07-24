<?php

namespace Splitstack\Compensable\Tests\Fixtures\Domain;

trait RecordsEvents
{
    /** @var GenericDomainEvent[] */
    private array $recordedEvents = [];

    public function recordEvent(string $event, mixed $payload): void
    {
        $this->recordedEvents[] = new GenericDomainEvent($event, $payload);
    }

    public function getRecordedEvents(): array
    {
        return $this->recordedEvents;
    }

    public function clearEvents(): void
    {
        $this->recordedEvents = [];
    }
}
