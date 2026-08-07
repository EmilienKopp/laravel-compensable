<?php

namespace Splitstack\Conveyor\Contracts;

abstract class SequencePayload
{
    private array $bag = [];

    private bool $transacting = false;

    /**
     * Whether the owning Sequence runs its steps inside a DB transaction.
     * Lets a step decide, at run time, whether committed writes still need
     * hand-rolled compensation (see CompensatesData).
     */
    public function transacting(): bool
    {
        return $this->transacting;
    }

    /**
     * @internal set by Sequence::run() from the declared transaction boundary
     */
    public function markTransacting(bool $transacting): void
    {
        $this->transacting = $transacting;
    }

    public function set(string $key, mixed $value): void
    {
        $this->bag[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (\array_key_exists($key, $this->bag)) {
            return $this->bag[$key];
        }

        return property_exists($this, $key) ? $this->$key : $default;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->bag)
            || property_exists($this, $key) && isset($this->$key);
    }
}
