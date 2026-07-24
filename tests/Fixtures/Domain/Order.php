<?php

namespace Splitstack\Compensable\Tests\Fixtures\Domain;

use Splitstack\Compensable\Domain\Contracts\HasDomainEvents;

class Order implements HasDomainEvents
{
    use RecordsEvents;

    public function __construct(
        public readonly int $id,
        public readonly string $customer,
        public readonly int $amount,
    ) {}
}
