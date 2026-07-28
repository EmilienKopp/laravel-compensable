<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Domain;

use Splitstack\Conveyor\Domain\Contracts\HasDomainEvents;

class Order implements HasDomainEvents
{
    use RecordsEvents;

    public function __construct(
        public readonly int $id,
        public readonly string $customer,
        public readonly int $amount,
    ) {}
}
