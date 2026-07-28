<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Actions;

use Splitstack\Conveyor\Concerns\IsSteppable;
use Splitstack\Conveyor\Contracts\Steppable;
use Splitstack\Conveyor\Contracts\Undoable;
use Splitstack\Conveyor\Tests\Fixtures\Payloads\CheckoutPayload;
use Splitstack\Conveyor\WorkflowAbortedException;

/**
 * Guard step: gracefully stops the workflow when there is nothing left
 * to do — completed work is kept, later steps never run.
 */
class AbortUnlessShippable implements Steppable, Undoable
{
    use IsSteppable;

    public function handle(CheckoutPayload $payload): void
    {
        if ($payload->get('shippable') !== true) {
            throw new WorkflowAbortedException('order does not need shipping');
        }
    }

    public function undo(mixed $result = null): void {}
}
