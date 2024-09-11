<?php

namespace NotificationChannels\Fcm\Test;

use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use NotificationChannels\Fcm\FcmChannel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FcmChannelTest extends TestCase
{
    /** @test */
    public function it_can_be_instantiated()
    {
        $events = Mockery::mock(Dispatcher::class);
        $logger = Mockery::mock(LoggerInterface::class);

        $channel = new FcmChannel($events, $logger);

        $this->assertInstanceOf(FcmChannel::class, $channel);
    }
}
