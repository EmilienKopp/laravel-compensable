<?php

namespace Splitstack\Compensable\Tests\Fixtures\Payloads;

use Splitstack\Compensable\Contracts\WorkflowPayload;

/**
 * Known-at-entry properties are readonly constructor promoted;
 * mid-pipeline values ('order', 'chargeId', 'shipmentRef') travel
 * through the inherited set/get/has bag.
 */
class CheckoutPayload extends WorkflowPayload
{
    public function __construct(
        public readonly string $customer,
        public readonly int $amount,
    ) {}
}
