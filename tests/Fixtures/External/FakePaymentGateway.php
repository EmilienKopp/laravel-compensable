<?php

namespace Splitstack\Conveyor\Tests\Fixtures\External;

/**
 * Stands in for a remote payment API — the kind of external state a DB
 * rollback can never touch, hence Compensable::undo().
 */
class FakePaymentGateway
{
    /** @var array<string, int> chargeId => amount */
    public array $charges = [];

    /** @var string[] */
    public array $refunds = [];

    public bool $failNextCharge = false;

    public bool $failNextRefund = false;

    public function charge(int $orderId, int $amount): string
    {
        if ($this->failNextCharge) {
            $this->failNextCharge = false;
            throw new \RuntimeException('payment declined');
        }

        $chargeId = 'ch_'.($orderId.'_'.count($this->charges));
        $this->charges[$chargeId] = $amount;

        return $chargeId;
    }

    public function refund(string $chargeId): void
    {
        if ($this->failNextRefund) {
            $this->failNextRefund = false;
            throw new \RuntimeException('refund endpoint unavailable');
        }

        $this->refunds[] = $chargeId;
    }
}
