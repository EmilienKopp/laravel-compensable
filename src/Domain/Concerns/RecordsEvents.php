<?php

namespace Splitstack\Conveyor\Domain\Concerns;


class RecordsEvents
{
    private array $recordedEvents = [];

    public function recordEvent(string $event, mixed $payload): void
    {
        $this->recordedEvents[$event] = $payload;
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