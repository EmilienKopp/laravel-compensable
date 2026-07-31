<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Steps;

use Illuminate\Support\Facades\DB;
use Splitstack\Conveyor\Concerns\IsSteppable;
use Splitstack\Conveyor\Contracts\Rewindable;
use Splitstack\Conveyor\Contracts\Steppable;
use Splitstack\Conveyor\RetryConfig;
use Splitstack\Conveyor\Tests\Fixtures\Payloads\CheckoutPayload;

/**
 * A queue-friendly step with no un-resolvable collaborators, so the job
 * can rebuild it from the container. Declares a RetryConfig so the test
 * can assert it maps onto the job's tries/backoff/timeout.
 */
class DelegatedAuditStep implements Steppable, Rewindable
{
    use IsSteppable;

    public function getRetryConfig(): ?RetryConfig
    {
        return RetryConfig::make(5, 2, 30);
    }

    public function handle(CheckoutPayload $payload): void
    {
        DB::table('audit')->insert(['action' => 'audit:handled']);
    }

    public function rewind(mixed $result = null): void
    {
        DB::table('audit')->insert(['action' => 'audit:rewound']);
    }
}
