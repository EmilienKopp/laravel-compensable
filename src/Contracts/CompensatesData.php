<?php

namespace Splitstack\Conveyor\Contracts;

/**
 * Reverse committed DB writes when no owning transaction will.
 *
 * A Sequence declared with dontTransact() (e.g. because a step makes a slow
 * external call and you don't want to hold DB locks across it) has no
 * transaction to roll back on failure. A unit that wrote to the database in
 * that mode implements CompensatesData to undo those writes by hand.
 *
 * compensateData() only runs when the owning Sequence is NOT transacting —
 * inside a transaction the rollback already reverted the writes.
 */
interface CompensatesData extends Compensable
{
    public function compensateData(): void;
}
