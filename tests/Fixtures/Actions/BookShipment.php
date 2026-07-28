<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Actions;

use Illuminate\Support\Facades\DB;
use Splitstack\Conveyor\Contracts\Action;
use Splitstack\Conveyor\Tests\Fixtures\Domain\Order;
use Splitstack\Conveyor\Tests\Fixtures\External\FakeShippingService;

/**
 * Payload-ignorant action: takes the Order it needs directly; the
 * BookShipmentStep adapter extracts it from the workflow payload.
 *
 * @extends Action<string>
 */
class BookShipment extends Action
{
    public function __construct(private readonly FakeShippingService $shipping) {}

    public function handle(...$args): string
    {
        /** @var Order $order */
        [$order] = $args;

        $ref = $this->shipping->book($order->id);

        DB::table('orders')->where('id', $order->id)->update(['shipment_ref' => $ref]);

        return $ref;
    }

    public function undo(mixed $result = null): void
    {
        $this->shipping->cancel($result);
    }
}
