<?php

namespace Splitstack\Conveyor\Tests\Fixtures\External;

class FakeShippingService
{
    /** @var string[] */
    public array $bookings = [];

    /** @var string[] */
    public array $cancellations = [];

    public bool $failNextBooking = false;

    public function book(int $orderId): string
    {
        if ($this->failNextBooking) {
            $this->failNextBooking = false;
            throw new \RuntimeException('carrier API timeout');
        }

        $ref = 'ship_'.$orderId;
        $this->bookings[] = $ref;

        return $ref;
    }

    public function cancel(string $ref): void
    {
        $this->cancellations[] = $ref;
    }
}
