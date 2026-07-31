<?php

namespace Splitstack\Conveyor\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splitstack\Conveyor\ConveyorStepJob;
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
use Splitstack\Conveyor\WorkflowPipeline;

class DelegateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('audit', function ($table) {
            $table->increments('id');
            $table->string('action');
        });

        config(['queue.default' => 'sync']);
    }

    private function pipeline(): WorkflowPipeline
    {
        return new WorkflowPipeline(new Transactioner);
    }

    public function test_delegate_dispatches_a_queued_job_instead_of_running_inline(): void
    {
        Bus::fake();

        $this->pipeline()
            ->delegate(new DelegatedAuditStep)
            ->run(new CheckoutPayload('alice', 100));

        // it was queued, not executed in-band
        Bus::assertChained([ConveyorStepJob::class]);
        Bus::assertDispatched(
            ConveyorStepJob::class,
            fn (ConveyorStepJob $job) => $job->stepClass === DelegatedAuditStep::class
        );
        $this->assertSame(0, DB::table('audit')->count());
    }

    public function test_multiple_delegated_steps_form_a_chain_in_declaration_order(): void
    {
        Bus::fake();

        $this->pipeline()
            ->delegate(new DelegatedAuditStep)
            ->delegate(new FailingDelegatedStep)
            ->run(new CheckoutPayload('alice', 100));

        Bus::assertChained([
            fn (ConveyorStepJob $job) => $job->stepClass === DelegatedAuditStep::class,
            fn (ConveyorStepJob $job) => $job->stepClass === FailingDelegatedStep::class,
        ]);
    }

    public function test_after_commit_defaults_on_and_can_be_turned_off(): void
    {
        Bus::fake();

        $this->pipeline()
            ->delegate(new DelegatedAuditStep)
            ->run(new CheckoutPayload('alice', 100));

        Bus::assertDispatched(
            ConveyorStepJob::class,
            fn (ConveyorStepJob $job) => $job->afterCommit === true
        );

        Bus::fake();

        $this->pipeline()
            ->delegate(new DelegatedAuditStep, afterCommit: false)
            ->run(new CheckoutPayload('alice', 100));

        Bus::assertDispatched(
            ConveyorStepJob::class,
            fn (ConveyorStepJob $job) => $job->afterCommit !== true
        );
    }

    public function test_retry_config_maps_onto_the_jobs_queue_properties(): void
    {
        Bus::fake();

        $this->pipeline()
            ->delegate(new DelegatedAuditStep)
            ->run(new CheckoutPayload('alice', 100));

        Bus::assertDispatched(ConveyorStepJob::class, function (ConveyorStepJob $job) {
            return $job->stepClass === DelegatedAuditStep::class
                && $job->tries === 5
                && $job->backoff === 2
                && $job->timeout === 30;
        });
    }

    public function test_a_step_without_retry_config_defaults_to_a_single_attempt(): void
    {
        Bus::fake();

        $this->pipeline()
            ->delegate(new FailingDelegatedStep)
            ->run(new CheckoutPayload('alice', 100));

        Bus::assertDispatched(
            ConveyorStepJob::class,
            fn (ConveyorStepJob $job) => $job->tries === 1 && $job->backoff === null
        );
    }

    public function test_a_failed_delegated_job_compensates_itself_via_rewind(): void
    {
        // real sync queue, dispatched immediately (no commit to wait for)
        try {
            $this->pipeline()
                ->delegate(new FailingDelegatedStep, afterCommit: false)
                ->run(new CheckoutPayload('alice', 100));
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('delegated step boom', $e->getMessage());
        }

        // the job's failed() hook ran the step's own rewind()
        $this->assertSame(
            ['failing:rewound'],
            DB::table('audit')->pluck('action')->all()
        );
    }

    public function test_a_delegated_step_runs_outside_the_transacts_transaction(): void
    {
        $placeOrder = new PlaceOrder(
            new Transactioner,
            new CreateOrder,
            new ChargePayment(new FakePaymentGateway),
        );

        $this->pipeline()
            ->transacts()
            ->steps([new PlaceOrderStep($placeOrder)])
            ->delegate(new ObservesCommittedOrderStep)
            ->run(new CheckoutPayload('frank', 42));

        // the delegated step waited for commit, so it saw the paid order
        $this->assertSame('paid', DB::table('orders')->value('status'));
        $this->assertSame('seen:paid', DB::table('audit')->value('action'));
    }
}
