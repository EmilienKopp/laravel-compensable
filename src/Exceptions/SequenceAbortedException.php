<?php

namespace Splitstack\Conveyor\Exceptions;

/**
 * Graceful early exit from a sequence: work completed so far is kept
 * (the transaction commits), no compensation runs, nothing is rethrown.
 */
class SequenceAbortedException extends \Exception {}
