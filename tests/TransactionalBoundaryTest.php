<?php

use Splitstack\Conveyor\TransactionalBoundary;
use Splitstack\Conveyor\Data\FailedCompensation;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;
use Splitstack\Conveyor\Tests\Fixtures\Actions\SpyAction;

test('successful execution returns the callback result', function () {
    $log = new \ArrayObject;
    $scope = scope();

    $result = $scope->execute(function (TransactionalBoundary $s) use ($log) {
        $s->step(new SpyAction('a', $log));

        return $s->step(new SpyAction('b', $log));
    });

    expect($result)->toBe('b');
    expect((array) $log)->toBe(['handle:a', 'handle:b']);
});

test('failure compensates succeeded actions in reverse order', function () {
    $log = new \ArrayObject;
    $scope = scope();

    try {
        $scope->execute(function (TransactionalBoundary $s) use ($log) {
            $s->step(new SpyAction('a', $log));
            $s->step(new SpyAction('b', $log));
            $s->step(new SpyAction('boom', $log, failOnHandle: true));
        });
        $this->fail('expected exception');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('boom failed');
    }

    // undo receives handle()'s result, and walks back b before a
    expect((array) $log)->toBe(['handle:a', 'handle:b', 'undo:b', 'undo:a']);
});

test('a failing undo does not abort the cascade', function () {
    $log = new \ArrayObject;
    $failures = [];
    $scope = scope()->onCompensationFailed(
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
    expect((array) $log)->toBe(['handle:a', 'handle:bad-undo', 'undo:a']);

    expect($failures)->toHaveCount(1);
    $failure = $failures[0];
    expect($failure->action)->toBeInstanceOf(SpyAction::class);
    expect($failure->result)->toBe('bad-undo');
    expect($failure->exception->getMessage())->toBe('undo of bad-undo failed');
    expect($failure->cause?->getMessage())->toBe('boom failed');
});

test('on error hook receives the original exception', function () {
    $seen = null;

    try {
        scope()->execute(
            fn () => throw new \RuntimeException('original'),
            onError: function (\Throwable $e) use (&$seen) {
                $seen = $e;
            }
        );
        $this->fail('expected exception');
    } catch (\RuntimeException) {
    }

    expect($seen?->getMessage())->toBe('original');
});
