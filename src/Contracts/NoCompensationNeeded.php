<?php

namespace Splitstack\Conveyor\Contracts;

/**
 * Marker: nothing to compensate (DB writes are the transaction's, or the unit
 * is read-only). Declares "no compensation" explicitly rather than by silence.
 */
interface NoCompensationNeeded extends Compensable {}
