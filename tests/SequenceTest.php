<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Splitstack\Conveyor\Data\FailedCompensation;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;
use Splitstack\Conveyor\Tests\Fixtures\Actions\AbortUnlessShippable;
use Splitstack\Conveyor\Tests\Fixtures\Actions\BookShipment;
use Splitstack\Conveyor\Tests\Fixtures\Actions\ChargePayment;
use Splitstack\Conveyor\Tests\Fixtures\Actions\CreateOrder;
use Splitstack\Conveyor\Tests\Fixtures\Domain\GenericDomainEvent;
use Splitstack\Conveyor\Tests\Fixtures\External\FakePaymentGateway;
use Splitstack\Conveyor\Tests\Fixtures\External\FakeShippingService;
use Splitstack\Conveyor\Tests\Fixtures\External\LegacyLoyaltyService;
use Splitstack\Conveyor\Tests\Fixtures\Payloads\CheckoutPayload;
use Splitstack\Conveyor\Tests\Fixtures\Steps\AwardLoyaltyPointsStep;
use Splitstack\Conveyor\Tests\Fixtures\Steps\BookShipmentStep;
use Splitstack\Conveyor\Tests\Fixtures\Steps\PlaceOrderStep;
use Splitstack\Conveyor\Tests\Fixtures\UseCases\PlaceOrder;
use Splitstack\Conveyor\Tests\Fixtures\Sequences\CheckoutSequence;
use Splitstack\Conveyor\Sequence;


beforeEach(function () {
    $this->gateway = new FakePaymentGateway;
    $this->shipping = new FakeShippingService;
    $this->placeOrder = new PlaceOrder(
        new Transactioner,
        new CreateOrder,
        new ChargePayment($this->gateway),
    );
    $this->sequence = new CheckoutSequence(
        new Transactioner,
        new PlaceOrderStep($this->placeOrder),
        new BookShipmentStep(new BookShipment($this->shipping)),
    );
});

test('the happy path runs every step and fires events once', function () {
    Event::fake([GenericDomainEvent::class]);

    $payload = $this->sequence->checkout('alice', 100);

    $row = DB::table('orders')->sole();
    expect($row->status)->toBe('paid');
    expect($row->shipment_ref)->toBe($payload->get('shipmentRef'));
    expect($this->shipping->bookings)->toBe([$row->shipment_ref]);

    // one order.placed, deferred until the OUTER transaction committed
    Event::assertDispatchedTimes(GenericDomainEvent::class, 1);
});

test('a late failure rolls back all db writes and compensates external state', function () {
    Event::fake([GenericDomainEvent::class]);
    $this->shipping->failNextBooking = true;

    try {
        $this->sequence->checkout('alice', 100);
        $this->fail('expected exception');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('carrier API timeout');
    }

    // PlaceOrder's savepoint-commit is swallowed by the outer rollback:
    // the Transactioner owns the whole DB story
    expect(DB::table('orders')->count())->toBe(0);

    // the charge is external — compensated via the undo cascade:
    // pipeline → PlaceOrder->rewind() → ChargePayment->rewind(chargeId)
    $charged = array_keys($this->gateway->charges);
    expect($charged)->toHaveCount(1);
    expect($this->gateway->refunds)->toBe($charged);

    // the booking never succeeded, so nothing to cancel; no events leaked
    expect($this->shipping->cancellations)->toBe([]);
    Event::assertNothingDispatched();
});

test('a failed compensation is reported and retryable', function () {
    $this->shipping->failNextBooking = true;
    $this->gateway->failNextRefund = true;

    // hooks are per-scope: the refund happens inside PlaceOrder's cascade
    $failures = [];
    $this->placeOrder->onCompensationFailed(
        function (FailedCompensation $f) use (&$failures) {
            $failures[] = $f;
        }
    );

    try {
        $this->sequence->checkout('alice', 100);
        $this->fail('expected exception');
    } catch (\RuntimeException) {
    }

    expect($failures)->toHaveCount(1);
    $failure = $failures[0];
    expect($failure->action)->toBeInstanceOf(ChargePayment::class);
    expect($failure->exception->getMessage())->toBe('refund endpoint unavailable');
    expect($this->gateway->refunds)->toBe([]);

    // what a queued retry job would do — Laravel's tries/backoff on top
    $failure->retry();
    expect($this->gateway->refunds)->toBe([$failure->result]);
});

test('skippable steps are skipped when the predicate fails', function () {
    $this->sequence->checkout('bob', 50, shippable: false);

    $row = DB::table('orders')->sole();
    expect($row->status)->toBe('paid');
    expect($row->shipment_ref)->toBeNull();
    expect($this->shipping->bookings)->toBe([]);
});

test('a step can declare compensation for a legacy non compensable class', function () {
    $loyalty = new LegacyLoyaltyService;
    $this->shipping->failNextBooking = true;

    try {
        new Sequence(new Transactioner)
            ->steps([
                new PlaceOrderStep($this->placeOrder),
                new AwardLoyaltyPointsStep($loyalty),
                new BookShipmentStep(new BookShipment($this->shipping)),
            ])
            ->run(new CheckoutPayload('erin', 60));
        $this->fail('expected exception');
    } catch (\RuntimeException) {
    }

    // the legacy service was compensated by the STEP's declared undo
    expect($loyalty->points['erin'])->toBe(0);

    // and the cascade still reached the charge behind the UseCase
    expect($this->gateway->refunds)->toBe(array_keys($this->gateway->charges));
});

test('a step with unsatisfied requires is skipped and never tracked', function () {
    $payload = new CheckoutPayload('dave', 25);

    // no 'order' in the payload — BookShipmentStep declares requires: ['order']
    new Sequence(new Transactioner)
        ->steps([new BookShipmentStep(new BookShipment($this->shipping))])
        ->run($payload);

    expect($this->shipping->bookings)->toBe([]);
    expect($payload->has('shipmentRef'))->toBeFalse();
});

test('abort keeps completed work and skips compensation', function () {
    Event::fake([GenericDomainEvent::class]);

    $payload = new CheckoutPayload('carol', 75);
    $payload->set('shippable', false);

    $result = new Sequence(new Transactioner)
        ->steps([new PlaceOrderStep($this->placeOrder), new AbortUnlessShippable])
        ->steps([new BookShipmentStep(new BookShipment($this->shipping))])
        ->run($payload);

    expect($result)->toBe($payload);

    // the abort committed PlaceOrder's work and stopped the sequence
    expect(DB::table('orders')->sole()->status)->toBe('paid');
    expect($this->shipping->bookings)->toBe([]);

    // no compensation: the charge stands, and the commit fired events
    expect($this->gateway->refunds)->toBe([]);
    Event::assertDispatchedTimes(GenericDomainEvent::class, 1);
});