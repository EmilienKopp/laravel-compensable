<?php

namespace Splitstack\Conveyor\Contracts;

interface Rewindable
{
    /**
     * Rewind EXTERNAL mutations only (API calls, S3 writes, ...).
     * DB rollback is fully owned by the Transactioner — rewind() must
     * never try to reverse database writes.
     *
     * For Actions and UseCases, $result is what handle() returned.
     * For pipeline steps, $result is the workflow passable.
     */
    public function rewind(mixed $result = null): void;
}
