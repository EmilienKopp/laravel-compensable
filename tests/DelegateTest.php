<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splitstack\Conveyor\Infrastructure\Queue\ConveyorStepJob;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;
use Splitstack\Conveyor\Tests\Fixtures\Actions\ChargePayment;
use Splitstack\Conveyor\Tests\Fixtures\Actions\CreateOrder;
use Splitstack\Conveyor\Tests\Fixtures\External\FakePaymentGateway;
use Splitstack\Conveyor\Tests\Fixtures\Payloads\CheckoutPayload;
use Splitstack\Conveyor\Tests\Fixtures\Steps\DelegatedAuditStep;
use Splitstack\Conveyor\Tests\Fixtures\Steps\FailingDelegatedStep;
use Splitstack\Conveyor\Tests\Fixtures\Steps\ObservesCommittedOrderStep;
use Splitstack\Conveyor\Tests\Fixtures\Steps\PlaceOrderStep;
use Splitstack\Conveyor\Tests\Fixtures\UseCases\PlaceOrder;
use Splitstack\Conveyor\Sequence;


beforeEach(function () {
    Schema::create('audit', function ($table) {
        $table->increments('id');
        $table->string('action');
    });

    config(['queue.default' => 'sync']);
});

function pipeline(): Sequence
{
    return new Sequence(new Transactioner);
}

test('delegate dispatches a queued job instead of running inline', function () {
    Bus::fake();

    pipeline()
        ->delegate(new DelegatedAuditStep)
        ->run(new CheckoutPayload('alice', 100));

    // it was queued, not executed in-band
    Bus::assertChained([ConveyorStepJob::class]);
    Bus::assertDispatched(
        ConveyorStepJob::class,
        fn (ConveyorStepJob $job) => $job->stepClass === DelegatedAuditStep::class
    );
    expect(DB::table('audit')->count())->toBe(0);
});

test('multiple delegated steps form a chain in declaration order', function () {
    Bus::fake();

    pipeline()
        ->delegate(new DelegatedAuditStep)
        ->delegate(new FailingDelegatedStep)
        ->run(new CheckoutPayload('alice', 100));

    Bus::assertChained([
        fn (ConveyorStepJob $job) => $job->stepClass === DelegatedAuditStep::class,
        fn (ConveyorStepJob $job) => $job->stepClass === FailingDelegatedStep::class,
    ]);
});

test('after commit defaults on and can be turned off', function () {
    Bus::fake();

    pipeline()
        ->delegate(new DelegatedAuditStep)
        ->run(new CheckoutPayload('alice', 100));

    Bus::assertDispatched(
        ConveyorStepJob::class,
        fn (ConveyorStepJob $job) => $job->afterCommit === true
    );

    Bus::fake();

    pipeline()
        ->delegate(new DelegatedAuditStep, afterCommit: false)
        ->run(new CheckoutPayload('alice', 100));

    Bus::assertDispatched(
        ConveyorStepJob::class,
        fn (ConveyorStepJob $job) => $job->afterCommit !== true
    );
});

test('retry config maps onto the jobs queue properties', function () {
    Bus::fake();

    pipeline()
        ->delegate(new DelegatedAuditStep)
        ->run(new CheckoutPayload('alice', 100));

    Bus::assertDispatched(ConveyorStepJob::class, function (ConveyorStepJob $job) {
        return $job->stepClass === DelegatedAuditStep::class
            && $job->tries === 5
            && $job->backoff === 2
            && $job->timeout === 30;
    });
});

test('a step without retry config defaults to a single attempt', function () {
    Bus::fake();

    pipeline()
        ->delegate(new FailingDelegatedStep)
        ->run(new CheckoutPayload('alice', 100));

    Bus::assertDispatched(
        ConveyorStepJob::class,
        fn (ConveyorStepJob $job) => $job->tries === 1 && $job->backoff === null
    );
});

test('a failed delegated job compensates itself via rewind', function () {
    // real sync queue, dispatched immediately (no commit to wait for)
    try {
        pipeline()
            ->delegate(new FailingDelegatedStep, afterCommit: false)
            ->run(new CheckoutPayload('alice', 100));
        $this->fail('expected exception');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('delegated step boom');
    }

    // the job's failed() hook ran the step's own rewind()
    expect(DB::table('audit')->pluck('action')->all())->toBe(['failing:rewound']);
});

test('a delegated step runs outside the transacts transaction', function () {
    $placeOrder = new PlaceOrder(
        new Transactioner,
        new CreateOrder,
        new ChargePayment(new FakePaymentGateway),
    );

    pipeline()
        ->transacts()
        ->steps([new PlaceOrderStep($placeOrder)])
        ->delegate(new ObservesCommittedOrderStep)
        ->run(new CheckoutPayload('frank', 42));

    // the delegated step waited for commit, so it saw the paid order
    expect(DB::table('orders')->value('status'))->toBe('paid');
    expect(DB::table('audit')->value('action'))->toBe('seen:paid');
});
