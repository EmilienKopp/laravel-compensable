<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Steps;

use Illuminate\Support\Facades\DB;
use Splitstack\Conveyor\Concerns\IsSteppable;
use Splitstack\Conveyor\Contracts\Rewindable;
use Splitstack\Conveyor\Contracts\Steppable;
use Splitstack\Conveyor\Tests\Fixtures\Payloads\CheckoutPayload;

/**
 * Always fails on the worker. With a single attempt the job exhausts its
 * retries immediately, so failed() runs the step's own rewind() — the
 * delegated step compensates itself, never the parent's rewind stack.
 */
class FailingDelegatedStep implements Steppable, Rewindable
{
    use IsSteppable;

    public function handle(CheckoutPayload $payload): void
    {
        throw new \RuntimeException('delegated step boom');
    }

    public function rewind(mixed $result = null): void
    {
        DB::table('audit')->insert(['action' => 'failing:rewound']);
    }
}
