<?php

namespace App\Tests\Service;

use App\Service\RetailCrmService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class RetailCrmServiceTest extends TestCase
{
    private const EMAIL      = 'alex.chichin@gmail.com';
    private const SECRET     = 'test-secret';
    private const EMAIL_B64  = 'YWxleC5jaGljaGluQGdtYWlsLmNvbQ=='; // старый формат без подписи
    private const DIALOG_ID  = '79282';
    private const ORDER_ID   = '12345';
    // -------------------------------------------------------------------------
    // extractEmailFromStart
    // -------------------------------------------------------------------------

    public function testExtractEmailFromStartSuccess(): void
    {
        $service = $this->makeService();
        $token   = $service->generateStartToken(self::EMAIL);

        $this->assertSame(self::EMAIL, $service->extractEmailFromStart('/start ' . $token));
    }

    public function testExtractEmailFromStartCaseInsensitive(): void
    {
        $service = $this->makeService();
        $token   = $service->generateStartToken(self::EMAIL);

        $this->assertSame(self::EMAIL, $service->extractEmailFromStart('/START ' . $token));
    }

    public function testExtractEmailFromStartReturnNullIfNotStartCommand(): void
    {
        $service = $this->makeService();

        $this->assertNull($service->extractEmailFromStart('hello world'));
        $this->assertNull($service->extractEmailFromStart('/help'));
    }

    public function testExtractEmailFromStartReturnNullIfInvalidHmac(): void
    {
        $service = $this->makeService();
        // токен сгенерирован с другим секретом
        $fakeToken = base64_encode(self::EMAIL . ':' . 'deadbeefdeadbeef');

        $this->assertNull($service->extractEmailFromStart('/start ' . $fakeToken));
    }

    public function testExtractEmailFromStartReturnNullIfNoSignature(): void
    {
        $service = $this->makeService();
        // старый формат — просто base64(email) без подписи
        $this->assertNull($service->extractEmailFromStart('/start ' . self::EMAIL_B64));
    }

    public function testExtractEmailFromStartReturnNullIfNotValidEmail(): void
    {
        $service = $this->makeService();
        $hmac    = substr(hash_hmac('sha256', 'notanemail', self::SECRET), 0, 16);

        $this->assertNull($service->extractEmailFromStart('/start ' . base64_encode('notanemail:' . $hmac)));
    }

    public function testExtractEmailFromStartReturnNullIfNotBase64(): void
    {
        $service = $this->makeService();

        $this->assertNull($service->extractEmailFromStart('/start !!!notbase64!!!'));
    }

    // -------------------------------------------------------------------------
    // getLastOrderByEmail
    // -------------------------------------------------------------------------

    public function testGetLastOrderByEmailReturnsOrder(): void
    {
        $order   = ['id' => self::ORDER_ID, 'number' => 'ORD-001', 'email' => self::EMAIL];
        $service = $this->makeService(new MockHttpClient([
            new MockResponse(json_encode([
                'success' => true,
                'orders'  => [$order],
            ])),
        ]));

        $result = $service->getLastOrderByEmail(self::EMAIL);

        $this->assertSame(self::ORDER_ID, $result['id']);
    }

    public function testGetLastOrderByEmailReturnsNullWhenNoOrders(): void
    {
        $service = $this->makeService(new MockHttpClient([
            new MockResponse(json_encode([
                'success' => true,
                'orders'  => [],
            ])),
        ]));

        $this->assertNull($service->getLastOrderByEmail(self::EMAIL));
    }

    // -------------------------------------------------------------------------
    // bindDialogToOrder
    // -------------------------------------------------------------------------

    public function testBindDialogToOrderSuccess(): void
    {
        $capturedBody = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '';
            return new MockResponse(json_encode(['success' => true]));
        });

        $service = $this->makeService($http);
        $result  = $service->bindDialogToOrder(self::ORDER_ID, self::DIALOG_ID);

        $this->assertTrue($result);

        // проверяем что order передаётся как form-поле с json внутри
        parse_str($capturedBody, $parsed);
        $order = json_decode($parsed['order'] ?? '{}', true);
        $this->assertSame((int)self::DIALOG_ID, $order['dialogId']);
    }

    public function testBindDialogToOrderReturnsFalseOnApiError(): void
    {
        $service = $this->makeService(new MockHttpClient([
            new MockResponse(json_encode(['success' => false, 'errorMsg' => 'Not found']), ['http_code' => 404]),
        ]));

        $this->assertFalse($service->bindDialogToOrder(self::ORDER_ID, self::DIALOG_ID));
    }

    // -------------------------------------------------------------------------

    private function makeService(?MockHttpClient $http = null): RetailCrmService
    {
        return new RetailCrmService(
            logger:          new NullLogger(),
            http:            $http ?? new MockHttpClient(),
            retailCrmUrl:    (string)getenv('RETAILCRM_URL'),
            retailCrmApiKey: (string)getenv('RETAILCRM_API_KEY'),
            retailCrmSite:   (string)getenv('RETAILCRM_SITE') ?: null,
            appSecret:       self::SECRET,
        );
    }
}
