<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Domain;

use Splitstack\Conveyor\Concerns\RecordsEvents;
use Splitstack\Conveyor\Contracts\HasDomainEvents;
use Splitstack\Conveyor\Contracts\IsDomainEvent;

class Order implements HasDomainEvents
{
    use RecordsEvents;

    public function __construct(
        public readonly int $id,
        public readonly string $customer,
        public readonly int $amount,
    ) {}

    protected function makeDomainEvent(string $event, mixed $payload): IsDomainEvent
    {
        return new GenericDomainEvent($event, $payload);
    }
}
