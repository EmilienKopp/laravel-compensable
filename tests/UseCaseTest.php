<?php

namespace Splitstack\Compensable\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Splitstack\Compensable\Infrastructure\Transaction\Transactioner;
use Splitstack\Compensable\Tests\Fixtures\Actions\ChargePayment;
use Splitstack\Compensable\Tests\Fixtures\Actions\CreateOrder;
use Splitstack\Compensable\Tests\Fixtures\Domain\GenericDomainEvent;
use Splitstack\Compensable\Tests\Fixtures\Domain\Order;
use Splitstack\Compensable\Tests\Fixtures\External\FakePaymentGateway;
use Splitstack\Compensable\Tests\Fixtures\UseCases\PlaceOrder;

class UseCaseTest extends TestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
    }

    private function placeOrder(): PlaceOrder
    {
        return new PlaceOrder(
            new Transactioner,
            new CreateOrder,
            new ChargePayment($this->gateway),
        );
    }

    public function test_success_commits_and_dispatches_events_after_commit(): void
    {
        Event::fake([GenericDomainEvent::class]);

        $order = $this->placeOrder()->handle('alice', 100);

        $this->assertInstanceOf(Order::class, $order);

        $row = DB::table('orders')->sole();
        $this->assertSame('paid', $row->status);
        $this->assertContains($row->charge_id, array_keys($this->gateway->charges));

        Event::assertDispatched(
            GenericDomainEvent::class,
            fn (GenericDomainEvent $e) => $e->getName() === 'order.placed'
                && $e->getPayload()['orderId'] === $order->id
        );
    }

    public function test_failure_rolls_back_the_db_and_dispatches_nothing(): void
    {
        Event::fake([GenericDomainEvent::class]);
        $this->gateway->failNextCharge = true;

        try {
            $this->placeOrder()->handle('alice', 100);
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('payment declined', $e->getMessage());
        }

        // the insert from CreateOrder is gone — owned by the transaction
        $this->assertSame(0, DB::table('orders')->count());
        // the charge never succeeded, so there is nothing to refund
        $this->assertSame([], $this->gateway->refunds);
        Event::assertNothingDispatched();
    }
}
