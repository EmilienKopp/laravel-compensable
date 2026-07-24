<?php

namespace Splitstack\Compensable;

use Illuminate\Support\ServiceProvider;
use Splitstack\Compensable\Infrastructure\Transaction\Transactioner;

class CompensableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Transactioner::class);

        $this->app->bind(WorkflowPipeline::class);
    }
}
