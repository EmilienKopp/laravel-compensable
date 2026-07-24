<?php

namespace Splitstack\Compensable\Contracts;

interface Undoable
{
    /**
     * Undo EXTERNAL mutations only (API calls, S3 writes, ...).
     * DB rollback is fully owned by the Transactioner — undo() must
     * never try to reverse database writes.
     *
     * For Actions and UseCases, $result is what handle() returned.
     * For pipeline steps, $result is the workflow passable.
     */
    public function undo(mixed $result = null): void;
}
