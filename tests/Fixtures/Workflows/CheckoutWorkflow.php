<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Workflows;

use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;
use Splitstack\Conveyor\Tests\Fixtures\Payloads\CheckoutPayload;
use Splitstack\Conveyor\Tests\Fixtures\Steps\BookShipmentStep;
use Splitstack\Conveyor\Tests\Fixtures\Steps\PlaceOrderStep;
use Splitstack\Conveyor\WorkflowPipeline;

/**
 * The target consuming syntax: a named workflow IS a WorkflowPipeline,
 * Steps injected via constructor, sequenced fluently.
 */
final class CheckoutWorkflow extends WorkflowPipeline
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
            ->steps([$this->placeOrder])
            ->skippable($this->bookShipment, fn (CheckoutPayload $p) => $p->get('shippable', true))
            ->run($payload);
    }
}
