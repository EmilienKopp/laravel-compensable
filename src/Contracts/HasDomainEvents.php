<?php

namespace Splitstack\Conveyor\Contracts;

interface HasDomainEvents
{
    /**
     * Record an event
     */
    public function recordEvent(string $event, mixed $payload): void;

    /**
     * Get the recorded events
     *
     * @return list<IsDomainEvent>
     */
    public function getRecordedEvents(): array;

    /**
     * Clear the recorded events
     */
    public function clearEvents(): void;
}
