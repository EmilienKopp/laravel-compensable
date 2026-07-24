<?php

namespace Splitstack\Compensable\Tests\Fixtures\Steps;

use Splitstack\Compensable\Concerns\IsSteppable;
use Splitstack\Compensable\Contracts\Steppable;
use Splitstack\Compensable\Contracts\Undoable;
use Splitstack\Compensable\Tests\Fixtures\External\LegacyLoyaltyService;
use Splitstack\Compensable\Tests\Fixtures\Payloads\CheckoutPayload;

/**
 * Adapts a legacy, non-Compensable service (execute() convention,
 * closed for modification) and declares the compensation the service
 * itself cannot: the undo is a property of the STEP. Stateless — the
 * passable handed to undo() carries the context.
 */
class AwardLoyaltyPointsStep implements Steppable, Undoable
{
    use IsSteppable;

    public const POINTS = 10;

    public function __construct(private readonly LegacyLoyaltyService $loyalty) {}

    public function handle(CheckoutPayload $payload): void
    {
        $this->loyalty->execute($payload->customer, self::POINTS);
    }

    public function undo(mixed $result = null): void
    {
        /** @var CheckoutPayload $result */
        $this->loyalty->revoke($result->customer, self::POINTS);
    }
}
