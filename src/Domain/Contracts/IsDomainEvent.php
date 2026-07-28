<?php

namespace Splitstack\Conveyor\Domain\Contracts;

interface IsDomainEvent
{
    public function getName(): string;

    public function getPayload(): mixed;
}
