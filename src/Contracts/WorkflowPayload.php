<?php

namespace Splitstack\Conveyor\Contracts;

abstract class WorkflowPayload
{
    private array $bag = [];

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
