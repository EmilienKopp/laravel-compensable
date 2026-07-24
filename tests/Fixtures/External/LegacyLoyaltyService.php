<?php

namespace Splitstack\Compensable\Tests\Fixtures\External;

/**
 * Stands in for pre-existing application code: uses the execute()
 * convention, knows nothing about Compensable, and is closed for
 * modification. The Step wrapping it declares the compensation.
 */
class LegacyLoyaltyService
{
    /** @var array<string, int> */
    public array $points = [];

    public function execute(string $customer, int $points): int
    {
        $this->points[$customer] = ($this->points[$customer] ?? 0) + $points;

        return $this->points[$customer];
    }

    public function revoke(string $customer, int $points): void
    {
        $this->points[$customer] -= $points;
    }
}
