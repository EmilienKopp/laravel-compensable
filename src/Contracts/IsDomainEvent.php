<?php

namespace Splitstack\Conveyor\Contracts;

interface IsDomainEvent
{
    public function getName(): string;

    public function getPayload(): mixed;
}
