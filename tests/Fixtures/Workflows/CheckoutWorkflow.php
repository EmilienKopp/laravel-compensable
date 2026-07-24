<?php

namespace Splitstack\Compensable\Tests\Fixtures\Workflows;

use Splitstack\Compensable\Infrastructure\Transaction\Transactioner;
use Splitstack\Compensable\Tests\Fixtures\Payloads\CheckoutPayload;
use Splitstack\Compensable\Tests\Fixtures\Steps\BookShipmentStep;
use Splitstack\Compensable\Tests\Fixtures\Steps\PlaceOrderStep;
use Splitstack\Compensable\WorkflowPipeline;

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
