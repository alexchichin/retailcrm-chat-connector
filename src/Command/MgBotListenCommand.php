<?php

namespace App\Command;

use App\Message\BindDialogMessage;
use App\Service\RetailCrmService;
use Psr\Log\LoggerInterface;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\Connector as ReactConnector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:mg:listen',
    description: 'Listen MG Bot WebSocket, decode /start <base64email>, find customer in RetailCRM and bind last order to dialog.'
)]
class MgBotListenCommand extends Command
{
    private LoopInterface $loop;
    private OutputInterface $output;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RetailCrmService $retailCrm,
        private readonly MessageBusInterface $bus,
        private readonly string $mgWsUrl,
        private readonly ?string $mgWsToken,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->loop   = Loop::get();
        $this->output = $output;

        $reactConnector = new ReactConnector(['timeout' => 20]);
        $connector = new WsConnector($this->loop, $reactConnector);

        $connect = function () use ($connector, $output, &$connect) {
            $headers = [];
            if ($this->mgWsToken) {
                $headers['X-Bot-Token'] = $this->mgWsToken;
            }

            $events = 'message_new,message_updated,dialog_assign,dialog_closed';
            $wsUrl  = $this->mgWsUrl . (str_contains($this->mgWsUrl, '?') ? '&' : '?') . 'events=' . urlencode($events);

            $output->writeln('<info>Connecting to WS: </info>' . $this->mgWsUrl);

            $connector($wsUrl, [], $headers)->then(
                function (WebSocket $conn) use ($output, &$connect) {
                    $output->writeln('<info>WS connected</info>');
                    $this->logger->info('WS connected');

                    $conn->on('message', function ($msg) {
                        $raw = (string)$msg;
                        $this->output->writeln('<comment>WS raw: </comment>' . $raw);
                        $this->handleIncomingMessage($raw);
                    });

                    $conn->on('close', function ($code = null, $reason = null) use ($output, &$connect) {
                        $output->writeln(sprintf('<comment>WS closed (%s): %s</comment>', (string)$code, (string)$reason));
                        $this->logger->warning('WS closed', ['code' => $code, 'reason' => $reason]);
                        $this->loop->addTimer(3.0, fn() => $connect());
                    });
                },
                function (\Throwable $e) use ($output, &$connect) {
                    $output->writeln('<error>WS connect failed: </error>' . $e->getMessage());
                    $this->logger->error('WS connect failed', ['exception' => $e]);
                    $this->loop->addTimer(5.0, fn() => $connect());
                }
            );
        };

        $connect();
        $this->loop->run();

        return Command::SUCCESS;
    }

    private function handleIncomingMessage(string $raw): void
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }

        $type = $data['type'] ?? '';
        if ($type !== 'message_new') {
            $this->output->writeln('<comment>WS event ignored (type=' . $type . ')</comment>');
            return;
        }

        $dialogId = $data['data']['message']['dialog']['id'] ?? null;
        $content  = $data['data']['message']['content'] ?? null;

        if (!$dialogId || !$content || !is_string($content)) {
            $this->output->writeln('<comment>message_new: missing dialogId or content</comment>');
            return;
        }

        $email = $this->retailCrm->extractEmailFromStart($content);
        if (!$email) {
            $this->output->writeln('<comment>message_new: not a /start command, content: ' . $content . '</comment>');
            return;
        }

        $this->output->writeln('<info>/start received</info> email=' . $email . ' dialogId=' . $dialogId);

        $this->bus->dispatch(new BindDialogMessage($email, (string)$dialogId));
        $this->output->writeln('<comment>BindDialogMessage dispatched</comment>');
    }
}
