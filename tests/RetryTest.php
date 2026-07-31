<?php

namespace Splitstack\Conveyor\Tests;

use Splitstack\Conveyor\TransactionalBoundary;
use Splitstack\Conveyor\Data\FailedStep;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;
use Splitstack\Conveyor\Tests\Fixtures\Actions\FlakeyAction;
use Splitstack\Conveyor\Tests\Fixtures\Actions\SpyAction;

class RetryTest extends TestCase
{
    private function scope(): TransactionalBoundary
    {
        return new TransactionalBoundary(new Transactioner());
    }

    public function test_action_succeeds_after_transient_failure(): void
    {
        $scope = $this->scope();

        $result = $scope->execute(
            fn(TransactionalBoundary $s) => $s->step(new FlakeyAction(succeedOnAttempt: 2))
        );

        $this->assertSame('ok:2', $result);
    }

    public function test_exhausted_retries_trigger_on_step_failed_hook(): void
    {
        $failure = null;
        $scope = $this->scope()->onStepFailed(function (FailedStep $f) use (&$failure) {
            $failure = $f;
        });

        try {
            $scope->execute(
                fn(TransactionalBoundary $s) => $s->step(new FlakeyAction(succeedOnAttempt: 99))
            );
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertNotNull($failure);
        $this->assertInstanceOf(FlakeyAction::class, $failure->action);
        $this->assertSame(3, $failure->attempts);
        $this->assertSame(0, $failure->retryConfig?->backoff);
    }

    public function test_exhausted_retries_still_trigger_compensation_for_prior_steps(): void
    {
        $log = new \ArrayObject();
        $scope = $this->scope()->onStepFailed(fn() => null);

        try {
            $scope->execute(function (TransactionalBoundary $s) use ($log) {
                $s->step(new SpyAction('a', $log));
                $s->step(new FlakeyAction(succeedOnAttempt: 99));
            });
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertContains('undo:a', (array) $log);
    }

    public function test_unrecoverable_error_bails_without_exhausting_retries(): void
    {
        $failure = null;
        $scope = $this->scope()->onStepFailed(function (FailedStep $f) use (&$failure) {
            $failure = $f;
        });

        try {
            $scope->execute(
                fn(TransactionalBoundary $s) => $s->step(
                    new FlakeyAction(succeedOnAttempt: 99, unrecoverableOnSecondFailure: true)
                )
            );
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        // bailed after 2 attempts, not 3
        $this->assertSame(2, $failure?->attempts);
    }

    public function test_failed_step_carries_retry_config_for_queue_dispatch(): void
    {
        $dispatched = null;
        $scope = $this->scope()->onStepFailed(function (FailedStep $f) use (&$dispatched) {
            // consumer would do: RetryStepJob::dispatch($f->action, $f->retryConfig)
            $dispatched = [
                'action'             => $f->action::class,
                'tries'   => $f->retryConfig?->tries,
                'backoff' => $f->retryConfig?->backoff,
                'timeout' => $f->retryConfig?->timeout,
            ];
        });

        try {
            $scope->execute(fn(TransactionalBoundary $s) => $s->step(new FlakeyAction(succeedOnAttempt: 99)));
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertSame(FlakeyAction::class, $dispatched['action']);
        $this->assertSame(3, $dispatched['tries']);
        $this->assertSame(0, $dispatched['backoff']);
        $this->assertSame(14, $dispatched['timeout']);
    }

    public function test_action_without_retry_config_fails_immediately(): void
    {
        $log = new \ArrayObject();
        $scope = $this->scope();

        try {
            $scope->execute(function (TransactionalBoundary $s) use ($log) {
                $s->step(new SpyAction('a', $log));
                $s->step(new SpyAction('boom', $log, failOnHandle: true));
            });
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        // no retry — 'boom' fired once, then compensation
        $this->assertSame(['handle:a', 'undo:a'], (array) $log);
    }
}
