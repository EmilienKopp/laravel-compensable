<?php

namespace Splitstack\Compensable\Contracts;

abstract class WorkflowPayload
{
    private array $bag = [];

    public function set(string $key, mixed $value): void
    {
        $this->bag[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->bag[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->bag);
    }
}
