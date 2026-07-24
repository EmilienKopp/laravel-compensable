<?php

namespace Splitstack\Compensable\Domain\Contracts;

interface IsDomainEvent
{
    public function getName(): string;

    public function getPayload(): mixed;
}
