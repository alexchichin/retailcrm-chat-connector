<?php

namespace App\MessageHandler;

use App\Message\BindDialogMessage;
use App\Service\RetailCrmService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
class BindDialogMessageHandler
{
    private const MAX_ATTEMPTS   = 10;
    private const RETRY_DELAY_MS = 30 * 60 * 1000; // 30 минут в мс

    public function __construct(
        private readonly RetailCrmService $retailCrm,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(BindDialogMessage $message): void
    {
        $this->logger->info('Processing BindDialogMessage', [
            'email'    => $message->email,
            'dialogId' => $message->dialogId,
            'attempt'  => $message->attempt,
        ]);

        $order = $this->retailCrm->getLastOrderByEmail($message->email);

        if (!$order) {
            if ($message->attempt >= self::MAX_ATTEMPTS) {
                $this->logger->error('Order not found after max attempts, giving up', [
                    'email'    => $message->email,
                    'dialogId' => $message->dialogId,
                ]);
                return;
            }

            $this->logger->info('Order not found, re-queuing', [
                'email'   => $message->email,
                'attempt' => $message->attempt,
            ]);

            $this->bus->dispatch(
                new BindDialogMessage($message->email, $message->dialogId, $message->attempt + 1),
                [new DelayStamp(self::RETRY_DELAY_MS)],
            );

            return;
        }

        $bound = $this->retailCrm->bindDialogToOrder((string)$order['id'], $message->dialogId);

        if ($bound) {
            $this->logger->info('Dialog successfully bound', [
                'orderId'  => $order['id'],
                'dialogId' => $message->dialogId,
            ]);
        }
    }
}
