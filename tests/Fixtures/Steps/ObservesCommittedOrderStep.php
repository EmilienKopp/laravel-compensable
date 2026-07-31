<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Steps;

use Illuminate\Support\Facades\DB;
use Splitstack\Conveyor\Concerns\IsSteppable;
use Splitstack\Conveyor\Contracts\Steppable;
use Splitstack\Conveyor\Tests\Fixtures\Payloads\CheckoutPayload;

/**
 * Reads the orders table on the worker and records what it saw. When the
 * pipeline transacts() and dispatch waits for commit, this step only sees
 * the committed row — proof it ran outside (after) the transaction.
 */
class ObservesCommittedOrderStep implements Steppable
{
    use IsSteppable;

    public function handle(CheckoutPayload $payload): void
    {
        $status = DB::table('orders')->value('status');

        DB::table('audit')->insert(['action' => 'seen:' . ($status ?? 'none')]);
    }
}
