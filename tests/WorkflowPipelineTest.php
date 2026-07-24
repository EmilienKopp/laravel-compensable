<?php

namespace Splitstack\Compensable\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Splitstack\Compensable\FailedCompensation;
use Splitstack\Compensable\Infrastructure\Transaction\Transactioner;
use Splitstack\Compensable\Tests\Fixtures\Actions\AbortUnlessShippable;
use Splitstack\Compensable\Tests\Fixtures\Actions\BookShipment;
use Splitstack\Compensable\Tests\Fixtures\Actions\ChargePayment;
use Splitstack\Compensable\Tests\Fixtures\Actions\CreateOrder;
use Splitstack\Compensable\Tests\Fixtures\Domain\GenericDomainEvent;
use Splitstack\Compensable\Tests\Fixtures\External\FakePaymentGateway;
use Splitstack\Compensable\Tests\Fixtures\External\FakeShippingService;
use Splitstack\Compensable\Tests\Fixtures\External\LegacyLoyaltyService;
use Splitstack\Compensable\Tests\Fixtures\Payloads\CheckoutPayload;
use Splitstack\Compensable\Tests\Fixtures\Steps\AwardLoyaltyPointsStep;
use Splitstack\Compensable\Tests\Fixtures\Steps\BookShipmentStep;
use Splitstack\Compensable\Tests\Fixtures\Steps\PlaceOrderStep;
use Splitstack\Compensable\Tests\Fixtures\UseCases\PlaceOrder;
use Splitstack\Compensable\Tests\Fixtures\Workflows\CheckoutWorkflow;
use Splitstack\Compensable\WorkflowPipeline;

class WorkflowPipelineTest extends TestCase
{
    private FakePaymentGateway $gateway;

    private FakeShippingService $shipping;

    private PlaceOrder $placeOrder;

    private CheckoutWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->shipping = new FakeShippingService;
        $this->placeOrder = new PlaceOrder(
            new Transactioner,
            new CreateOrder,
            new ChargePayment($this->gateway),
        );
        $this->workflow = new CheckoutWorkflow(
            new Transactioner,
            new PlaceOrderStep($this->placeOrder),
            new BookShipmentStep(new BookShipment($this->shipping)),
        );
    }

    public function test_the_happy_path_runs_every_step_and_fires_events_once(): void
    {
        Event::fake([GenericDomainEvent::class]);

        $payload = $this->workflow->checkout('alice', 100);

        $row = DB::table('orders')->sole();
        $this->assertSame('paid', $row->status);
        $this->assertSame($payload->get('shipmentRef'), $row->shipment_ref);
        $this->assertSame([$row->shipment_ref], $this->shipping->bookings);

        // one order.placed, deferred until the OUTER transaction committed
        Event::assertDispatchedTimes(GenericDomainEvent::class, 1);
    }

    public function test_a_late_failure_rolls_back_all_db_writes_and_compensates_external_state(): void
    {
        Event::fake([GenericDomainEvent::class]);
        $this->shipping->failNextBooking = true;

        try {
            $this->workflow->checkout('alice', 100);
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('carrier API timeout', $e->getMessage());
        }

        // PlaceOrder's savepoint-commit is swallowed by the outer rollback:
        // the Transactioner owns the whole DB story
        $this->assertSame(0, DB::table('orders')->count());

        // the charge is external — compensated via the undo cascade:
        // pipeline → PlaceOrder->undo() → ChargePayment->undo(chargeId)
        $charged = array_keys($this->gateway->charges);
        $this->assertCount(1, $charged);
        $this->assertSame($charged, $this->gateway->refunds);

        // the booking never succeeded, so nothing to cancel; no events leaked
        $this->assertSame([], $this->shipping->cancellations);
        Event::assertNothingDispatched();
    }

    public function test_a_failed_compensation_is_reported_and_retryable(): void
    {
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
            $this->workflow->checkout('alice', 100);
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertCount(1, $failures);
        $failure = $failures[0];
        $this->assertInstanceOf(ChargePayment::class, $failure->action);
        $this->assertSame('refund endpoint unavailable', $failure->exception->getMessage());
        $this->assertSame([], $this->gateway->refunds);

        // what a queued retry job would do — Laravel's tries/backoff on top
        $failure->retry();
        $this->assertSame([$failure->result], $this->gateway->refunds);
    }

    public function test_skippable_steps_are_skipped_when_the_predicate_fails(): void
    {
        $this->workflow->checkout('bob', 50, shippable: false);

        $row = DB::table('orders')->sole();
        $this->assertSame('paid', $row->status);
        $this->assertNull($row->shipment_ref);
        $this->assertSame([], $this->shipping->bookings);
    }

    public function test_a_step_can_declare_compensation_for_a_legacy_non_compensable_class(): void
    {
        $loyalty = new LegacyLoyaltyService;
        $this->shipping->failNextBooking = true;

        try {
            (new WorkflowPipeline(new Transactioner))
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
        $this->assertSame(0, $loyalty->points['erin']);
        // and the cascade still reached the charge behind the UseCase
        $this->assertSame(array_keys($this->gateway->charges), $this->gateway->refunds);
    }

    public function test_a_step_with_unsatisfied_requires_is_skipped_and_never_tracked(): void
    {
        $payload = new CheckoutPayload('dave', 25);
        // no 'order' in the payload — BookShipmentStep declares requires: ['order']

        (new WorkflowPipeline(new Transactioner))
            ->steps([new BookShipmentStep(new BookShipment($this->shipping))])
            ->run($payload);

        $this->assertSame([], $this->shipping->bookings);
        $this->assertFalse($payload->has('shipmentRef'));
    }

    public function test_abort_keeps_completed_work_and_skips_compensation(): void
    {
        Event::fake([GenericDomainEvent::class]);

        $payload = new CheckoutPayload('carol', 75);
        $payload->set('shippable', false);

        $result = (new WorkflowPipeline(new Transactioner))
            ->steps([new PlaceOrderStep($this->placeOrder), new AbortUnlessShippable])
            ->steps([new BookShipmentStep(new BookShipment($this->shipping))])
            ->run($payload);

        $this->assertSame($payload, $result);

        // the abort committed PlaceOrder's work and stopped the sequence
        $this->assertSame('paid', DB::table('orders')->sole()->status);
        $this->assertSame([], $this->shipping->bookings);

        // no compensation: the charge stands, and the commit fired events
        $this->assertSame([], $this->gateway->refunds);
        Event::assertDispatchedTimes(GenericDomainEvent::class, 1);
    }
}
