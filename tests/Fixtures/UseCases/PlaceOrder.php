<?php

namespace Splitstack\Compensable\Tests\Fixtures\UseCases;

use Splitstack\Compensable\Contracts\UseCase;
use Splitstack\Compensable\Infrastructure\Transaction\Transactioner;
use Splitstack\Compensable\Tests\Fixtures\Actions\ChargePayment;
use Splitstack\Compensable\Tests\Fixtures\Actions\CreateOrder;
use Splitstack\Compensable\Tests\Fixtures\Domain\Order;

/**
 * One business operation, one (save-pointed) transaction, two Actions.
 * Knows nothing about workflows or payloads — a Step adapts it into a
 * pipeline. Domain events recorded on the Order are dispatched only
 * after the outermost transaction commits.
 */
class PlaceOrder extends UseCase
{
    public function __construct(
        Transactioner $transactioner,
        private readonly CreateOrder $createOrder,
        private readonly ChargePayment $chargePayment,
    ) {
        parent::__construct($transactioner);
    }

    public function handle(...$args): Order
    {
        [$customer, $amount] = $args;

        return $this->executeWithEvents(function () use ($customer, $amount): Order {
            /** @var Order $order */
            $order = $this->step($this->createOrder, $customer, $amount);
            $chargeId = $this->step($this->chargePayment, $order);

            $order->recordEvent('order.placed', ['orderId' => $order->id, 'chargeId' => $chargeId]);

            return $order;
        });
    }
}
