<?php

namespace Splitstack\Conveyor\Tests;

use Illuminate\Support\Facades\DB;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;
use Splitstack\Conveyor\Tests\Fixtures\Domain\GenericDomainEvent;
use Splitstack\Conveyor\Tests\Fixtures\Domain\Order;
use Splitstack\Conveyor\Tests\Fixtures\Domain\RecordsEvents;

class TransactionerTest extends TestCase
{
    private function transactioner(): Transactioner
    {
        return new Transactioner();
    }

    public function test_execute_commits_and_returns_result(): void
    {
        $result = $this->transactioner()->execute(function () {
            DB::table('orders')->insert(['customer' => 'alice', 'amount' => 100, 'status' => 'pending']);

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_execute_rolls_back_on_exception(): void
    {
        try {
            $this->transactioner()->execute(function () {
                DB::table('orders')->insert(['customer' => 'alice', 'amount' => 100, 'status' => 'pending']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_on_throw_hook_receives_the_exception(): void
    {
        $seen = null;

        try {
            $this->transactioner()
                ->onThrow(function (\Throwable $e) use (&$seen) {
                    $seen = $e;
                })
                ->execute(fn() => throw new \RuntimeException('original'));
        } catch (\RuntimeException) {
        }

        $this->assertSame('original', $seen?->getMessage());
    }

    public function test_execute_with_events_dispatches_after_commit(): void
    {
        $dispatched = [];
        $dispatcher = function (GenericDomainEvent $e) use (&$dispatched) {
            $dispatched[] = $e->getName();
        };

        $domainObject = $this->makeDomainObject();
        $domainObject->recordEvent('order.placed', []);

        $this->transactioner()->executeWithEvents(
            fn() => $domainObject,
            dispatcher: $dispatcher
        );

        $this->assertSame(['order.placed'], $dispatched);
    }

    public function test_execute_with_events_does_not_dispatch_on_failure(): void
    {
        $dispatched = [];
        $dispatcher = function (GenericDomainEvent $e) use (&$dispatched) {
            $dispatched[] = $e->getName();
        };

        $domainObject = $this->makeDomainObject();
        $domainObject->recordEvent('order.placed', []);

        try {
            $this->transactioner()->executeWithEvents(
                function () use ($domainObject) {
                    DB::table('orders')->insert(['customer' => 'bob', 'amount' => 50, 'status' => 'pending']);
                    throw new \RuntimeException('boom');

                    return $domainObject;
                },
                dispatcher: $dispatcher
            );
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $dispatched);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_custom_dispatcher_is_used_instead_of_laravel_event(): void
    {
        $custom = [];
        $domainObject = $this->makeDomainObject();
        $domainObject->recordEvent('something.happened', ['key' => 'value']);

        $this->transactioner()->executeWithEvents(
            fn() => $domainObject,
            dispatcher: function (GenericDomainEvent $e) use (&$custom) {
                $custom[] = [$e->getName(), $e->getPayload()];
            }
        );

        $this->assertCount(1, $custom);
        $this->assertSame('something.happened', $custom[0][0]);
        $this->assertSame(['key' => 'value'], $custom[0][1]);
    }

    private function makeDomainObject(): Order
    {
        return new Order(1, 'test', 0);
    }
}
