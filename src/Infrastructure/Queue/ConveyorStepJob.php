<?php

namespace Splitstack\Conveyor\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Splitstack\Conveyor\Contracts\CompensatesData;
use Splitstack\Conveyor\Contracts\Rewindable;
use Splitstack\Conveyor\Contracts\Steppable;
use Splitstack\Conveyor\Data\RetryConfig;

/**
 * Runs a delegated step on the queue. Carries the step's class name and
 * the serializable payload — never the step instance, whose collaborators
 * are re-injected by the container on handle(). Serialization of the
 * payload is the developer's problem: if it doesn't serialize, Laravel
 * throws, and that's the signal.
 *
 * Retry policy comes from the step's getRetryConfig(): tries drives
 * $tries, backoff drives $backoff, timeout drives $timeout. A null
 * config means a single attempt.
 *
 * Compensation is self-contained: once retries are exhausted, failed()
 * calls the step's rewind(). The parent pipeline's rewind stack does not
 * track delegated steps — they own their own undo.
 */
class ConveyorStepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public ?int $backoff = null;

    public ?int $timeout = null;

    /**
     * @param  class-string<Steppable>  $stepClass
     */
    public function __construct(
        public readonly string $stepClass,
        public readonly mixed $payload,
    ) {}

    public static function for(Steppable $step, mixed $payload): self
    {
        $job = new self($step::class, $payload);
        $job->applyRetryConfig($step);

        return $job;
    }

    public function handle(): void
    {
        $step = app()->make($this->stepClass);

        $step($this->payload, null);
    }

    public function failed(\Throwable $e): void
    {
        $step = app()->make($this->stepClass);

        if ($step instanceof Rewindable) {
            $step->rewind($this->payload);
        }

        // A delegated step runs on the queue, outside any Sequence transaction,
        // so its committed writes need hand-rolled compensation too.
        if ($step instanceof CompensatesData) {
            $step->compensateData();
        }
    }

    private function applyRetryConfig(Steppable $step): void
    {
        if (! method_exists($step, 'getRetryConfig')) {
            return;
        }

        $config = $step->getRetryConfig();

        if (! $config instanceof RetryConfig) {
            return;
        }

        $this->tries = $config->tries;
        $this->backoff = $config->backoff;
        $this->timeout = $config->timeout;
    }
}
