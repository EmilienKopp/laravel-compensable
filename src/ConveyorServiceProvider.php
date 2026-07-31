<?php

namespace Splitstack\Conveyor;

use Illuminate\Support\ServiceProvider;
use Splitstack\Conveyor\Infrastructure\Transaction\Transactioner;

class ConveyorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Transactioner::class);

        $this->app->bind(Sequence::class);
    }
}
