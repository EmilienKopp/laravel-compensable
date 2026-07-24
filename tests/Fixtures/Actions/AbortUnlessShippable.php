<?php

namespace Splitstack\Compensable\Tests\Fixtures\Actions;

use Splitstack\Compensable\Contracts\Action;
use Splitstack\Compensable\Tests\Fixtures\Payloads\CheckoutPayload;
use Splitstack\Compensable\WorkflowAbortedException;

/**
 * Guard step: gracefully stops the workflow when there is nothing left
 * to do — completed work is kept, later steps never run.
 *
 * @extends Action<null>
 */
class AbortUnlessShippable extends Action
{
    public function handle(...$args): mixed
    {
        /** @var CheckoutPayload $payload */
        [$payload] = $args;

        if ($payload->get('shippable') !== true) {
            throw new WorkflowAbortedException('order does not need shipping');
        }

        return null;
    }

    public function undo(mixed $result = null): void
    {
        // guard only — nothing to compensate
    }
}
