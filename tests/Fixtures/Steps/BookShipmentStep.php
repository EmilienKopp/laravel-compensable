<?php

namespace Splitstack\Compensable\Tests\Fixtures\Steps;

use Splitstack\Compensable\Concerns\IsSteppable;
use Splitstack\Compensable\Contracts\Steppable;
use Splitstack\Compensable\Contracts\Undoable;
use Splitstack\Compensable\Tests\Fixtures\Actions\BookShipment;
use Splitstack\Compensable\Tests\Fixtures\Payloads\CheckoutPayload;

/**
 * Declares its dependency on a mid-pipeline value: without an 'order'
 * in the payload, the step self-skips (and is never tracked for undo).
 * undo() receives the passable, so compensation context comes from the
 * payload — not from a DB row that may already be rolled back.
 */
class BookShipmentStep implements Steppable, Undoable
{
    use IsSteppable;

    public function __construct(private readonly BookShipment $bookShipment) {}

    public function requires(): array
    {
        return ['order'];
    }

    public function handle(CheckoutPayload $payload): void
    {
        $ref = $this->bookShipment->handle($payload->get('order'));

        $payload->set('shipmentRef', $ref);
    }

    public function undo(mixed $result = null): void
    {
        /** @var CheckoutPayload $result */
        $this->bookShipment->undo($result->get('shipmentRef'));
    }
}
