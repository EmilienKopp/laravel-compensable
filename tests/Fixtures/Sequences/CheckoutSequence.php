<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Sequences;

use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;
use Splitstack\Conveyor\Tests\Fixtures\Payloads\CheckoutPayload;
use Splitstack\Conveyor\Tests\Fixtures\Steps\BookShipmentStep;
use Splitstack\Conveyor\Tests\Fixtures\Steps\PlaceOrderStep;
use Splitstack\Conveyor\Sequence;

/**
 * The target consuming syntax: a named sequence IS a Sequence,
 * Steps injected via constructor, sequenced fluently.
 */
final class CheckoutSequence extends Sequence
{
    public function __construct(
        Transactioner $transactioner,
        private readonly PlaceOrderStep $placeOrder,
        private readonly BookShipmentStep $bookShipment,
    ) {
        parent::__construct($transactioner);
    }

    public function checkout(string $customer, int $amount, bool $shippable = true): CheckoutPayload
    {
        $payload = new CheckoutPayload(
            customer: $customer,
            amount: $amount,
        );
        $payload->set('shippable', $shippable);

        /** @var CheckoutPayload */
        return $this
            ->transacts()
            ->steps([$this->placeOrder])
            ->skippable($this->bookShipment, fn (CheckoutPayload $p) => $p->get('shippable', true))
            ->run($payload);
    }
}
