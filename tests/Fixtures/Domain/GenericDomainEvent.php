<?php

namespace Splitstack\Compensable\Tests\Fixtures\Domain;

use Splitstack\Compensable\Domain\Contracts\IsDomainEvent;

final class GenericDomainEvent implements IsDomainEvent
{
    public function __construct(
        private readonly string $name,
        private readonly mixed $payload,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }
}
