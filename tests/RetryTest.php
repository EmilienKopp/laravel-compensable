<?php

namespace Splitstack\Compensable\Tests;

use Splitstack\Compensable\CompensableScope;
use Splitstack\Compensable\FailedStep;
use Splitstack\Compensable\Infrastructure\Transaction\Transactioner;
use Splitstack\Compensable\Tests\Fixtures\Actions\FlakeyAction;
use Splitstack\Compensable\Tests\Fixtures\Actions\SpyAction;

class RetryTest extends TestCase
{
    private function scope(): CompensableScope
    {
        return new CompensableScope(new Transactioner());
    }

    public function test_action_succeeds_after_transient_failure(): void
    {
        $scope = $this->scope();

        $result = $scope->execute(
            fn(CompensableScope $s) => $s->step(new FlakeyAction(succeedOnAttempt: 2))
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
                fn(CompensableScope $s) => $s->step(new FlakeyAction(succeedOnAttempt: 99))
            );
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertNotNull($failure);
        $this->assertInstanceOf(FlakeyAction::class, $failure->action);
        $this->assertSame(3, $failure->attempts);
        $this->assertSame(0, $failure->retryConfig?->retryAfterSeconds);
    }

    public function test_exhausted_retries_still_trigger_compensation_for_prior_steps(): void
    {
        $log = new \ArrayObject();
        $scope = $this->scope()->onStepFailed(fn() => null);

        try {
            $scope->execute(function (CompensableScope $s) use ($log) {
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
                fn(CompensableScope $s) => $s->step(
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
                'tries'              => $f->retryConfig?->tries,
                'retryAfterSeconds'  => $f->retryConfig?->retryAfterSeconds,
                'timeoutSeconds'     => $f->retryConfig?->timeoutSeconds,
            ];
        });

        try {
            $scope->execute(fn(CompensableScope $s) => $s->step(new FlakeyAction(succeedOnAttempt: 99)));
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertSame(FlakeyAction::class, $dispatched['action']);
        $this->assertSame(3, $dispatched['tries']);
        $this->assertSame(0, $dispatched['retryAfterSeconds']);
        $this->assertSame(14, $dispatched['timeoutSeconds']);
    }

    public function test_action_without_retry_config_fails_immediately(): void
    {
        $log = new \ArrayObject();
        $scope = $this->scope();

        try {
            $scope->execute(function (CompensableScope $s) use ($log) {
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
