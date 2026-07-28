<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Actions;

use Illuminate\Support\Facades\DB;
use Splitstack\Conveyor\Contracts\Action;
use Splitstack\Conveyor\Tests\Fixtures\Domain\Order;
use Splitstack\Conveyor\Tests\Fixtures\External\FakePaymentGateway;

/**
 * Mixed action: the DB update rolls back with the transaction; the remote
 * charge does not — undo() refunds it, using the chargeId that handle()
 * returned (never a DB lookup: that row may already be rolled back).
 *
 * @extends Action<string>
 */
class ChargePayment extends Action
{
    public function __construct(private readonly FakePaymentGateway $gateway) {}

    public function handle(...$args): string
    {
        /** @var Order $order */
        [$order] = $args;

        $chargeId = $this->gateway->charge($order->id, $order->amount);

        DB::table('orders')->where('id', $order->id)->update([
            'status' => 'paid',
            'charge_id' => $chargeId,
        ]);

        return $chargeId;
    }

    public function undo(mixed $result = null): void
    {
        $this->gateway->refund($result);
    }
}
