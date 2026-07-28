<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Steps;

use Splitstack\Conveyor\Concerns\IsSteppable;
use Splitstack\Conveyor\Contracts\Steppable;
use Splitstack\Conveyor\Contracts\Undoable;
use Splitstack\Conveyor\Tests\Fixtures\Payloads\CheckoutPayload;
use Splitstack\Conveyor\Tests\Fixtures\UseCases\PlaceOrder;

/**
 * Thin adapter: extracts from the payload, calls the UseCase that
 * already exists, writes the result back. Compensation delegates to the
 * UseCase's own cascade.
 */
class PlaceOrderStep implements Steppable, Undoable
{
    use IsSteppable;

    public function __construct(private readonly PlaceOrder $placeOrder) {}

    public function handle(CheckoutPayload $payload): void
    {
        $order = $this->placeOrder->handle($payload->customer, $payload->amount);

        $payload->set('order', $order);
    }

    public function undo(mixed $result = null): void
    {
        $this->placeOrder->undo();
    }
}
