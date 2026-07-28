<?php

namespace Splitstack\Conveyor;

/**
 * Graceful early exit from a workflow: work completed so far is kept
 * (the transaction commits), no compensation runs, nothing is rethrown.
 */
class WorkflowAbortedException extends \Exception {}
