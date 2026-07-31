<?php

use Illuminate\Support\Facades\DB;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;
use Splitstack\Conveyor\Tests\Fixtures\Domain\GenericDomainEvent;
use Splitstack\Conveyor\Tests\Fixtures\Domain\Order;
use Splitstack\Conveyor\Tests\Fixtures\Domain\RecordsEvents;


function transactioner(): Transactioner
{
    return new Transactioner();
}

test('execute commits and returns result', function () {
    $result = transactioner()->execute(function () {
        DB::table('orders')->insert(['customer' => 'alice', 'amount' => 100, 'status' => 'pending']);

        return 'done';
    });

    expect($result)->toBe('done');
    expect(DB::table('orders')->count())->toBe(1);
});

test('execute rolls back on exception', function () {
    try {
        transactioner()->execute(function () {
            DB::table('orders')->insert(['customer' => 'alice', 'amount' => 100, 'status' => 'pending']);
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException) {
    }

    expect(DB::table('orders')->count())->toBe(0);
});

test('on throw hook receives the exception', function () {
    $seen = null;

    try {
        transactioner()
            ->onThrow(function (\Throwable $e) use (&$seen) {
                $seen = $e;
            })
            ->execute(fn() => throw new \RuntimeException('original'));
    } catch (\RuntimeException) {
    }

    expect($seen?->getMessage())->toBe('original');
});

test('execute with events dispatches after commit', function () {
    $dispatched = [];
    $dispatcher = function (GenericDomainEvent $e) use (&$dispatched) {
        $dispatched[] = $e->getName();
    };

    $domainObject = makeDomainObject();
    $domainObject->recordEvent('order.placed', []);

    transactioner()->executeWithEvents(
        fn() => $domainObject,
        dispatcher: $dispatcher
    );

    expect($dispatched)->toBe(['order.placed']);
});

test('execute with events does not dispatch on failure', function () {
    $dispatched = [];
    $dispatcher = function (GenericDomainEvent $e) use (&$dispatched) {
        $dispatched[] = $e->getName();
    };

    $domainObject = makeDomainObject();
    $domainObject->recordEvent('order.placed', []);

    try {
        transactioner()->executeWithEvents(
            function () use ($domainObject) {
                DB::table('orders')->insert(['customer' => 'bob', 'amount' => 50, 'status' => 'pending']);
                throw new \RuntimeException('boom');

                return $domainObject;
            },
            dispatcher: $dispatcher
        );
    } catch (\RuntimeException) {
    }

    expect($dispatched)->toBe([]);
    expect(DB::table('orders')->count())->toBe(0);
});

test('custom dispatcher is used instead of laravel event', function () {
    $custom = [];
    $domainObject = makeDomainObject();
    $domainObject->recordEvent('something.happened', ['key' => 'value']);

    transactioner()->executeWithEvents(
        fn() => $domainObject,
        dispatcher: function (GenericDomainEvent $e) use (&$custom) {
            $custom[] = [$e->getName(), $e->getPayload()];
        }
    );

    expect($custom)->toHaveCount(1);
    expect($custom[0][0])->toBe('something.happened');
    expect($custom[0][1])->toBe(['key' => 'value']);
});

function makeDomainObject(): Order
{
    return new Order(1, 'test', 0);
}
