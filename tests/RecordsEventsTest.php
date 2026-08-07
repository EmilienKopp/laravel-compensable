<?php

test('a class with RecordEvents trait can record and retrieve events', function () {
    $order = new \Splitstack\Conveyor\Tests\Fixtures\Domain\Order(1, 'Alice', 100);
    $order->recordEvent(new \Splitstack\Conveyor\Tests\Fixtures\Domain\GenericDomainEvent('OrderCreated', ['id' => 1, 'customer' => 'Alice', 'amount' => 100]));

    $events = $order->getRecordedEvents();

    expect($events)->toHaveCount(1);
    expect($events[0]->event)->toBe('OrderCreated');
    expect($events[0]->payload)->toBe(['id' => 1, 'customer' => 'Alice', 'amount' => 100]);
});