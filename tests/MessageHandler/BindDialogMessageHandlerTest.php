<?php

namespace App\Tests\MessageHandler;

use App\Message\BindDialogMessage;
use App\MessageHandler\BindDialogMessageHandler;
use App\Service\RetailCrmService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class BindDialogMessageHandlerTest extends TestCase
{
    private const EMAIL     = 'alex.chichin@gmail.com';
    private const DIALOG_ID = '79282';
    private const ORDER_ID  = '12345';

    public function testOrderFoundAndDialogBound(): void
    {
        $order   = ['id' => self::ORDER_ID];
        $service = $this->createMock(RetailCrmService::class);
        $service->expects($this->once())->method('getLastOrderByEmail')->with(self::EMAIL)->willReturn($order);
        $service->expects($this->once())->method('bindDialogToOrder')->with(self::ORDER_ID, self::DIALOG_ID)->willReturn(true);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->makeHandler($service, $bus)(new BindDialogMessage(self::EMAIL, self::DIALOG_ID));
    }

    public function testOrderNotFoundRequeuesWithDelay(): void
    {
        $service = $this->createMock(RetailCrmService::class);
        $service->method('getLastOrderByEmail')->willReturn(null);
        $service->expects($this->never())->method('bindDialogToOrder');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(fn($msg) => $msg instanceof BindDialogMessage && $msg->attempt === 2),
                $this->callback(fn($stamps) => $stamps[0] instanceof DelayStamp),
            )
            ->willReturn(new Envelope(new BindDialogMessage(self::EMAIL, self::DIALOG_ID, 2)));

        $this->makeHandler($service, $bus)(new BindDialogMessage(self::EMAIL, self::DIALOG_ID, 1));
    }

    public function testOrderNotFoundAfterMaxAttemptsGivesUp(): void
    {
        $service = $this->createMock(RetailCrmService::class);
        $service->method('getLastOrderByEmail')->willReturn(null);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->makeHandler($service, $bus)(new BindDialogMessage(self::EMAIL, self::DIALOG_ID, 10));
    }

    public function testBindFailureIsLogged(): void
    {
        $order   = ['id' => self::ORDER_ID];
        $service = $this->createMock(RetailCrmService::class);
        $service->method('getLastOrderByEmail')->willReturn($order);
        $service->method('bindDialogToOrder')->willReturn(false);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        // просто не должно бросить исключение
        $this->makeHandler($service, $bus)(new BindDialogMessage(self::EMAIL, self::DIALOG_ID));
    }

    private function makeHandler(RetailCrmService $service, MessageBusInterface $bus): BindDialogMessageHandler
    {
        return new BindDialogMessageHandler($service, $bus, new NullLogger());
    }
}
