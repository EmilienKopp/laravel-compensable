<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Actions;

use Illuminate\Support\Facades\DB;
use Splitstack\Conveyor\Contracts\Action;
use Splitstack\Conveyor\Tests\Fixtures\Domain\Order;

/**
 * Pure-DB action: the owning scope's transaction reverts the insert,
 * so "nothing to undo" is the explicit, declared answer.
 *
 * @extends Action<Order>
 */
class CreateOrder extends Action
{
    public function handle(...$args): Order
    {
        [$customer, $amount] = $args;

        $id = DB::table('orders')->insertGetId([
            'customer' => $customer,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        return new Order($id, $customer, $amount);
    }

    public function rewind(mixed $result = null): void
    {
        // DB-only mutation — rollback is owned by the transaction.
    }
}
