<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:mg:register',
    description: 'Register or update the MG Bot module in RetailCRM and print the token and WS URL.'
)]
class MgBotRegisterCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $retailCrmUrl,
        private readonly string $retailCrmApiKey,
        private readonly string $retailCrmSite,
        private readonly string $appBaseUrl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code    = 'chat-connector';
        $url     = rtrim($this->retailCrmUrl, '/') . '/api/v5/integration-modules/' . $code . '/edit';

        $payload = [
            'integrationModule' => json_encode([
                'code'        => $code,
                'active'      => true,
                'name'        => 'Chat Connector',
                'clientId'    => $code,
                'baseUrl'     => $this->appBaseUrl,
                'accountUrl'  => $this->appBaseUrl,
                'integrations' => [
                    'mgBot' => (object)[],
                ],
            ]),
        ];

        $output->writeln('<info>Registering MG Bot module in RetailCRM...</info>');
        $output->writeln('POST ' . $url);

        try {
            $response = $this->http->request('POST', $url, [
                'query'   => ['site' => $this->retailCrmSite],
                'headers' => ['X-API-KEY' => $this->retailCrmApiKey],
                'body'    => http_build_query($payload),
            ]);

            $body = $response->toArray(false);

            if (!($body['success'] ?? false)) {
                $output->writeln('<error>Registration failed: ' . json_encode($body) . '</error>');
                return Command::FAILURE;
            }

            $token       = $body['info']['mgBot']['token'] ?? null;
            $endpointUrl = $body['info']['mgBot']['endpointUrl'] ?? null;

            if (!$token || !$endpointUrl) {
                $output->writeln('<error>Registration succeeded but token/endpointUrl missing in response.</error>');
                $output->writeln(json_encode($body, JSON_PRETTY_PRINT));
                return Command::FAILURE;
            }

            $wsUrl = rtrim($endpointUrl, '/') . '/ws';

            $output->writeln('');
            $output->writeln('<info>Registration successful! Add to your .env.local:</info>');
            $output->writeln('');
            $output->writeln('MG_WS_URL="' . $wsUrl . '"');
            $output->writeln('MG_WS_TOKEN="' . $token . '"');
            $output->writeln('');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Request failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
