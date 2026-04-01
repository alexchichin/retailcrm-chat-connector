<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RetailCrmService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $http,
        private readonly string $retailCrmUrl,
        private readonly string $retailCrmApiKey,
        private readonly ?string $retailCrmSite,
        private readonly string $appSecret,
    ) {}

    public function extractEmailFromStart(string $text): ?string
    {
        $text = trim($text);
        if (!preg_match('~^/start\s+(\S+)~i', $text, $m)) {
            return null;
        }

        $decoded = base64_decode($m[1], strict: true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode(':', $decoded, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$email, $receivedHmac] = $parts;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $expectedHmac = substr(hash_hmac('sha256', $email, $this->appSecret), 0, 16);
        if (!hash_equals($expectedHmac, $receivedHmac)) {
            $this->logger->warning('Invalid HMAC in /start payload', ['email' => $email]);
            return null;
        }

        return $email;
    }

    public function generateStartToken(string $email): string
    {
        $hmac = substr(hash_hmac('sha256', $email, $this->appSecret), 0, 16);
        return base64_encode($email . ':' . $hmac);
    }

    public function getLastOrderByEmail(string $email): ?array
    {
        $url   = rtrim($this->retailCrmUrl, '/') . '/api/v5/orders';
        $query = [
            'filter' => [
                'email'          => $email,
                'createdAtFrom'  => (new \DateTimeImmutable('-24 hours'))->format('Y-m-d'),
            ],
            'limit' => 20,
        ];
        if ($this->retailCrmSite) {
            $query['site'] = $this->retailCrmSite;
        }

        try {
            $body = $this->http->request('GET', $url, [
                'query'   => $query,
                'headers' => ['X-API-KEY' => $this->retailCrmApiKey],
            ])->toArray(false);

            if (!($body['success'] ?? false)) {
                $this->logger->error('RetailCRM orders search failed', ['response' => $body]);
                return null;
            }

            return $body['orders'][0] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error('RetailCRM orders request exception', ['exception' => $e]);
            return null;
        }
    }

    public function bindDialogToOrder(string $orderId, string $dialogId): bool
    {
        $url   = rtrim($this->retailCrmUrl, '/') . '/api/v5/orders/' . rawurlencode($orderId) . '/edit';
        $query = ['by' => 'id'];
        if ($this->retailCrmSite) {
            $query['site'] = $this->retailCrmSite;
        }

        $orderData = json_encode(['dialogId' => (int)$dialogId]);

        try {
            $response = $this->http->request('POST', $url, [
                'query'   => $query,
                'headers' => ['X-API-KEY' => $this->retailCrmApiKey],
                'body'    => http_build_query(['order' => $orderData]),
            ]);

            $status = $response->getStatusCode();
            $body   = $response->toArray(false);

            if ($status >= 200 && $status < 300 && ($body['success'] ?? false)) {
                $this->logger->info('Dialog bound to order', ['orderId' => $orderId, 'dialogId' => $dialogId]);
                return true;
            }

            $this->logger->error('RetailCRM order edit failed', ['orderId' => $orderId, 'status' => $status, 'response' => $body]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('RetailCRM order edit exception', ['orderId' => $orderId, 'exception' => $e]);
            return false;
        }
    }
}
