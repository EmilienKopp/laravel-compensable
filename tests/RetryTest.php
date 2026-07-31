<?php

use Splitstack\Conveyor\TransactionalBoundary;
use Splitstack\Conveyor\Data\FailedStep;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;
use Splitstack\Conveyor\Tests\Fixtures\Actions\FlakeyAction;
use Splitstack\Conveyor\Tests\Fixtures\Actions\SpyAction;

test('action succeeds after transient failure', function () {
    $scope = scope();

    $result = $scope->execute(
        fn(TransactionalBoundary $s) => $s->step(new FlakeyAction(succeedOnAttempt: 2))
    );

    expect($result)->toBe('ok:2');
});

test('exhausted retries trigger on step failed hook', function () {
    $failure = null;
    $scope = scope()->onStepFailed(function (FailedStep $f) use (&$failure) {
        $failure = $f;
    });

    try {
        $scope->execute(
            fn(TransactionalBoundary $s) => $s->step(new FlakeyAction(succeedOnAttempt: 99))
        );
        $this->fail('expected exception');
    } catch (\RuntimeException) {
    }

    expect($failure)->not->toBeNull();
    expect($failure->action)->toBeInstanceOf(FlakeyAction::class);
    expect($failure->attempts)->toBe(3);
    expect($failure->retryConfig?->backoff)->toBe(0);
});

test('exhausted retries still trigger compensation for prior steps', function () {
    $log = new \ArrayObject();
    $scope = scope()->onStepFailed(fn() => null);

    try {
        $scope->execute(function (TransactionalBoundary $s) use ($log) {
            $s->step(new SpyAction('a', $log));
            $s->step(new FlakeyAction(succeedOnAttempt: 99));
        });
        $this->fail('expected exception');
    } catch (\RuntimeException) {
    }

    expect((array) $log)->toContain('undo:a');
});

test('unrecoverable error bails without exhausting retries', function () {
    $failure = null;
    $scope = scope()->onStepFailed(function (FailedStep $f) use (&$failure) {
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
    expect($failure?->attempts)->toBe(2);
});

test('failed step carries retry config for queue dispatch', function () {
    $dispatched = null;
    $scope = scope()->onStepFailed(function (FailedStep $f) use (&$dispatched) {
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

    expect($dispatched['action'])->toBe(FlakeyAction::class);
    expect($dispatched['tries'])->toBe(3);
    expect($dispatched['backoff'])->toBe(0);
    expect($dispatched['timeout'])->toBe(14);
});

test('action without retry config fails immediately', function () {
    $log = new \ArrayObject();
    $scope = scope();

    try {
        $scope->execute(function (TransactionalBoundary $s) use ($log) {
            $s->step(new SpyAction('a', $log));
            $s->step(new SpyAction('boom', $log, failOnHandle: true));
        });
        $this->fail('expected exception');
    } catch (\RuntimeException) {
    }

    // no retry — 'boom' fired once, then compensation
    expect((array) $log)->toBe(['handle:a', 'undo:a']);
});
