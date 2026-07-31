<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;
use Splitstack\Conveyor\Tests\Fixtures\Actions\ChargePayment;
use Splitstack\Conveyor\Tests\Fixtures\Actions\CreateOrder;
use Splitstack\Conveyor\Tests\Fixtures\Domain\GenericDomainEvent;
use Splitstack\Conveyor\Tests\Fixtures\Domain\Order;
use Splitstack\Conveyor\Tests\Fixtures\External\FakePaymentGateway;
use Splitstack\Conveyor\Tests\Fixtures\UseCases\PlaceOrder;


beforeEach(function () {
    $this->gateway = new FakePaymentGateway;
});

function placeOrder(FakePaymentGateway $gateway): PlaceOrder
{
    return new PlaceOrder(
        new Transactioner,
        new CreateOrder,
        new ChargePayment($gateway),
    );
}

test('success commits and dispatches events after commit', function () {
    Event::fake([GenericDomainEvent::class]);

    $order = placeOrder($this->gateway)->handle('alice', 100);

    expect($order)->toBeInstanceOf(Order::class);

    $row = DB::table('orders')->sole();
    expect($row->status)->toBe('paid');
    expect(array_keys($this->gateway->charges))->toContain($row->charge_id);

    Event::assertDispatched(
        GenericDomainEvent::class,
        fn (GenericDomainEvent $e) => $e->getName() === 'order.placed'
            && $e->getPayload()['orderId'] === $order->id
    );
});

test('failure rolls back the db and dispatches nothing', function () {
    Event::fake([GenericDomainEvent::class]);
    $this->gateway->failNextCharge = true;

    try {
        placeOrder($this->gateway)->handle('alice', 100);
        $this->fail('expected exception');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('payment declined');
    }

    // the insert from CreateOrder is gone — owned by the transaction
    expect(DB::table('orders')->count())->toBe(0);

    // the charge never succeeded, so there is nothing to refund
    expect($this->gateway->refunds)->toBe([]);
    Event::assertNothingDispatched();
});