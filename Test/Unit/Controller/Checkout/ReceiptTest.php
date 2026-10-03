<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Checkout;

require_once __DIR__ . '/../../autoload.php';

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Controller\Checkout\Receipt;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\ReceiptService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class ReceiptTest extends TestCase
{
    private array $params = [];
    private ?int $code = null;
    private string $contents = '';
    private array $headers = [];
    private bool $enabled = true;
    private ReceiptService&MockObject $receiptService;
    private AuthService&MockObject $authService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->params = [];
        $this->code = null;
        $this->contents = '';
        $this->headers = [];
        $this->enabled = true;
        $this->receiptService = $this->createMock(ReceiptService::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function dispatch(): Raw
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $this->params[$k] ?? $d);
        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use ($raw) {
            $this->headers[$name] = $value;
            return $raw;
        });
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) {
            $this->code = $code;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($contents) use ($raw) {
            $this->contents = $contents;
            return $raw;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn () => $this->enabled);

        return (new Receipt($request, $rawFactory, $this->receiptService, $this->authService, $config, $this->logger))->execute();
    }

    public function testDisabledPosIs404(): void
    {
        $this->enabled = false;

        $this->dispatch();

        $this->assertSame(404, $this->code);
        $this->assertStringContainsString('<p>POS is disabled.</p>', $this->contents);
        $this->assertSame('text/html; charset=UTF-8', $this->headers['Content-Type']);
    }

    public function testMissingOrderIdIs400(): void
    {
        $this->dispatch();

        $this->assertSame(400, $this->code);
        $this->assertStringContainsString('Missing or invalid order_id.', $this->contents);
    }

    public function testUnknownOrderIs404(): void
    {
        $this->params = ['order_id' => '5'];
        $this->receiptService->method('getReceiptData')->willThrowException(new NoSuchEntityException(__('x')));

        $this->dispatch();

        $this->assertSame(404, $this->code);
        $this->assertStringContainsString('Receipt not found.', $this->contents);
    }

    public function testDataFailureIs500AndLogged(): void
    {
        $this->params = ['order_id' => '5'];
        $this->receiptService->method('getReceiptData')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('boom'));

        $this->dispatch();

        $this->assertSame(500, $this->code);
    }

    public function testAnonymousWithoutTokenIsForbidden(): void
    {
        $this->params = ['order_id' => '5', 'token' => 'wrong'];
        $this->receiptService->method('getReceiptData')->willReturn(['receipt_token' => 'secret']);
        $this->receiptService->expects($this->never())->method('renderHtml');

        $this->dispatch();

        $this->assertSame(403, $this->code);
        $this->assertStringContainsString('You are not allowed to view this receipt.', $this->contents);
    }

    public function testOrderWithoutTokenIsNeverPublic(): void
    {
        $this->params = ['order_id' => '5', 'token' => ''];
        $this->receiptService->method('getReceiptData')->willReturn(['receipt_token' => '']);
        $this->authService->method('getCurrentUser')->willThrowException(new \RuntimeException('no session'));

        $this->dispatch();

        $this->assertSame(403, $this->code);
    }

    public function testValidTokenRendersReceipt(): void
    {
        $this->params = ['order_id' => '5', 'token' => ' secret '];
        $this->receiptService->method('getReceiptData')->willReturn(['receipt_token' => 'secret']);
        $this->receiptService->method('renderHtml')->with(5)->willReturn('<html>receipt</html>');

        $this->dispatch();

        $this->assertNull($this->code);
        $this->assertSame('<html>receipt</html>', $this->contents);
    }

    public function testSignedInCashierNeedsNoToken(): void
    {
        $this->params = ['order_id' => '5'];
        $this->authService->method('getCurrentUser')->willReturn($this->createStub(PosUserInterface::class));
        $this->receiptService->method('getReceiptData')->willReturn(['receipt_token' => 'secret']);
        $this->receiptService->method('renderHtml')->willReturn('<html>ok</html>');

        $this->dispatch();

        $this->assertSame('<html>ok</html>', $this->contents);
    }

    public function testRenderFailureIs500(): void
    {
        $this->params = ['order_id' => '5', 'token' => 'secret'];
        $this->receiptService->method('getReceiptData')->willReturn(['receipt_token' => 'secret']);
        $this->receiptService->method('renderHtml')->willThrowException(new \RuntimeException('tpl'));
        $this->logger->expects($this->once())->method('error');

        $this->dispatch();

        $this->assertSame(500, $this->code);
        $this->assertStringContainsString('Unable to render the receipt.', $this->contents);
    }
}
