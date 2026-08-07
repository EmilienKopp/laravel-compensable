<?php

namespace Splitstack\Conveyor\Contracts;

/**
 * Parent marker for a unit's compensation decision: Rewindable (external
 * effects), CompensatesData (committed DB writes), or NoCompensationNeeded.
 * Enforced at definition time by CompensationRules so a unit can never be
 * silently left uncompensated.
 */
interface Compensable {}
