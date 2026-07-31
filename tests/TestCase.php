<?php

namespace Splitstack\Conveyor\Tests;

use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * @property \Splitstack\Conveyor\Tests\Fixtures\External\FakePaymentGateway $gateway
 * @property \Splitstack\Conveyor\Tests\Fixtures\External\FakeShippingService $shipping
 * @property \Splitstack\Conveyor\Tests\Fixtures\UseCases\PlaceOrder $placeOrder
 * @property \Splitstack\Conveyor\Tests\Fixtures\Sequences\CheckoutSequence $sequence
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function ($table) {
            $table->increments('id');
            $table->string('customer');
            $table->integer('amount');
            $table->string('status');
            $table->string('charge_id')->nullable();
            $table->string('shipment_ref')->nullable();
        });
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
