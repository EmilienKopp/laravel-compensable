<?php

namespace Splitstack\Compensable\Tests\Fixtures\Steps;

use Splitstack\Compensable\Concerns\IsSteppable;
use Splitstack\Compensable\Contracts\Steppable;
use Splitstack\Compensable\Contracts\Undoable;
use Splitstack\Compensable\Tests\Fixtures\Payloads\CheckoutPayload;
use Splitstack\Compensable\Tests\Fixtures\UseCases\PlaceOrder;

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
