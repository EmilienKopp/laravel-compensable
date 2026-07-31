<?php

namespace Splitstack\Conveyor\Contracts;

interface Skippable
{
    public function skips(mixed $passable): bool;
}
