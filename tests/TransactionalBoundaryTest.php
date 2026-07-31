<?php

namespace Splitstack\Conveyor\Tests;

use Splitstack\Conveyor\TransactionalBoundary;
use Splitstack\Conveyor\Data\FailedCompensation;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;
use Splitstack\Conveyor\Tests\Fixtures\Actions\SpyAction;

class TransactionalBoundaryTest extends TestCase
{
    private function scope(): TransactionalBoundary
    {
        return new TransactionalBoundary(new Transactioner);
    }

    public function test_successful_execution_returns_the_callback_result(): void
    {
        $log = new \ArrayObject;
        $scope = $this->scope();

        $result = $scope->execute(function (TransactionalBoundary $s) use ($log) {
            $s->step(new SpyAction('a', $log));

            return $s->step(new SpyAction('b', $log));
        });

        $this->assertSame('b', $result);
        $this->assertSame(['handle:a', 'handle:b'], (array) $log);
    }

    public function test_failure_compensates_succeeded_actions_in_reverse_order(): void
    {
        $log = new \ArrayObject;
        $scope = $this->scope();

        try {
            $scope->execute(function (TransactionalBoundary $s) use ($log) {
                $s->step(new SpyAction('a', $log));
                $s->step(new SpyAction('b', $log));
                $s->step(new SpyAction('boom', $log, failOnHandle: true));
            });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom failed', $e->getMessage());
        }

        // undo receives handle()'s result, and walks back b before a
        $this->assertSame(['handle:a', 'handle:b', 'undo:b', 'undo:a'], (array) $log);
    }

    public function test_a_failing_undo_does_not_abort_the_cascade(): void
    {
        $log = new \ArrayObject;
        $failures = [];
        $scope = $this->scope()->onCompensationFailed(
            function (FailedCompensation $f) use (&$failures) {
                $failures[] = $f;
            }
        );

        try {
            $scope->execute(function (TransactionalBoundary $s) use ($log) {
                $s->step(new SpyAction('a', $log));
                $s->step(new SpyAction('bad-undo', $log, failOnUndo: true));
                $s->step(new SpyAction('boom', $log, failOnHandle: true));
            });
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        // 'a' was still compensated even though 'bad-undo' threw
        $this->assertSame(['handle:a', 'handle:bad-undo', 'undo:a'], (array) $log);

        $this->assertCount(1, $failures);
        $failure = $failures[0];
        $this->assertInstanceOf(SpyAction::class, $failure->action);
        $this->assertSame('bad-undo', $failure->result);
        $this->assertSame('undo of bad-undo failed', $failure->exception->getMessage());
        $this->assertSame('boom failed', $failure->cause?->getMessage());
    }

    public function test_on_error_hook_receives_the_original_exception(): void
    {
        $seen = null;

        try {
            $this->scope()->execute(
                fn () => throw new \RuntimeException('original'),
                onError: function (\Throwable $e) use (&$seen) {
                    $seen = $e;
                }
            );
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertSame('original', $seen?->getMessage());
    }
}
