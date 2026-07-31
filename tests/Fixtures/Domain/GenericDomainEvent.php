<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Domain;

use Splitstack\Conveyor\Contracts\IsDomainEvent;

final class GenericDomainEvent implements IsDomainEvent
{
    public function __construct(
        public readonly string $event,
        public readonly mixed $payload,
    ) {}

    public function getName(): string
    {
        return $this->event;
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }
}
