<?php

namespace Splitstack\Conveyor\Tests\Fixtures\Steps;

use Splitstack\Conveyor\Concerns\IsSteppable;
use Splitstack\Conveyor\Contracts\Steppable;
use Splitstack\Conveyor\Contracts\Rewindable;
use Splitstack\Conveyor\Tests\Fixtures\Actions\BookShipment;
use Splitstack\Conveyor\Tests\Fixtures\Payloads\CheckoutPayload;

/**
 * Declares its dependency on a mid-pipeline value: without an 'order'
 * in the payload, the step self-skips (and is never tracked for undo).
 * rewind() receives the passable, so compensation context comes from the
 * payload — not from a DB row that may already be rolled back.
 */
class BookShipmentStep implements Steppable, Rewindable
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

    public function rewind(mixed $result = null): void
    {
        /** @var CheckoutPayload $result */
        $this->bookShipment->rewind($result->get('shipmentRef'));
    }
}
