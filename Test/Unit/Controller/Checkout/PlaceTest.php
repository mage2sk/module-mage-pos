<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Checkout;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Panth\MagePos\Controller\Checkout\Place;
use Panth\MagePos\Service\CheckoutService;
use Panth\MagePos\Service\ReceiptService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class PlaceTest extends AbstractControllerTestCase
{
    private CheckoutService&MockObject $checkoutService;
    private ReceiptService&MockObject $receiptService;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutService = $this->createMock(CheckoutService::class);
        $this->receiptService = $this->createMock(ReceiptService::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config->method('getGuestEmail')->willReturn('pos-guest@example.com');
    }

    private function dispatch(): void
    {
        (new Place(
            $this->jsonFactory,
            $this->formKeyValidator,
            $this->authService,
            $this->request,
            $this->checkoutService,
            $this->receiptService,
            $this->orderRepository,
            $this->config,
            new JsonSerializer(),
            $this->logger
        ))->execute();
    }

    private function orderWithEmail(string $email): void
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getCustomerEmail')->willReturn($email);
        $this->orderRepository->method('get')->with(55)->willReturn($order);
    }

    public function testRequiresQuoteId(): void
    {
        $this->signIn();
        $this->body = ['payments' => []];

        $this->dispatch();

        $this->assertError('quote_id is required.');
    }

    public function testPlacesOrderWithNormalisedOptions(): void
    {
        $this->signIn();
        $this->body = [
            'quote_id' => '3', 'payments' => ['a' => ['method_code' => 'cash', 'amount' => 10]],
            'note' => '  gift ', 'client_uuid' => 'u-1', 'is_offline_sync' => 1, 'register_id' => '4',
        ];
        $this->checkoutService->expects($this->once())->method('placeOrder')->with(
            3,
            [['method_code' => 'cash', 'amount' => 10]],
            ['note' => 'gift', 'client_uuid' => 'u-1', 'is_offline_sync' => true, 'register_id' => 4]
        )->willReturn(['order_id' => 55]);
        $this->receiptService->expects($this->never())->method('email');

        $this->dispatch();

        $this->assertSuccess(['order_id' => 55]);
    }

    public function testBlankNoteAndMissingOptionsAreOmitted(): void
    {
        $this->signIn();
        $this->params = ['quote_id' => 3, 'note' => '   '];
        $this->checkoutService->expects($this->once())->method('placeOrder')->with(3, [], [])->willReturn([]);

        $this->dispatch();

        $this->assertSuccess([]);
    }

    public function testExplicitReceiptEmailIsSent(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'email_receipt' => true, 'receipt_email' => ' a@b.co '];
        $this->checkoutService->method('placeOrder')->willReturn(['order_id' => 55]);
        $this->receiptService->expects($this->once())->method('email')->with(55, 'a@b.co');

        $this->dispatch();

        $this->assertSuccess(['order_id' => 55]);
    }

    public function testAutoEmailUsesCustomerEmail(): void
    {
        $this->signIn();
        $this->config->method('isAutoEmailReceipt')->willReturn(true);
        $this->body = ['quote_id' => 3];
        $this->checkoutService->method('placeOrder')->willReturn(['order_id' => 55]);
        $this->orderWithEmail('ann@x.test');
        $this->receiptService->expects($this->once())->method('email')->with(55, 'ann@x.test');

        $this->dispatch();
    }

    public function testAutoEmailSkipsGuestPlaceholderAddress(): void
    {
        $this->signIn();
        $this->config->method('isAutoEmailReceipt')->willReturn(true);
        $this->body = ['quote_id' => 3];
        $this->checkoutService->method('placeOrder')->willReturn(['order_id' => 55]);
        $this->orderWithEmail('POS-GUEST@example.com');
        $this->receiptService->expects($this->never())->method('email');

        $this->dispatch();

        $this->assertTrue($this->result['success']);
    }

    public function testReceiptEmailFailureIsLoggedButOrderStillSucceeds(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'email_receipt' => 1, 'receipt_email' => 'a@b.co'];
        $this->checkoutService->method('placeOrder')->willReturn(['order_id' => 55]);
        $this->receiptService->method('email')->willThrowException(new \RuntimeException('smtp'));
        $this->logger->expects($this->once())->method('error')->with('[Panth_MagePos] Receipt email failed: smtp');

        $this->dispatch();

        $this->assertSuccess(['order_id' => 55]);
    }

    public function testInvalidFormKeyFromBody(): void
    {
        $this->signIn();
        $this->formKeyValid = false;
        $this->body = ['form_key' => 'x', 'quote_id' => 3];
        $this->checkoutService->expects($this->never())->method('placeOrder');

        $this->dispatch();

        $this->assertSame(['form_key' => 'x'], $this->params);
        $this->assertError('Invalid form key.', 'invalid_form_key', 403);
    }

    public function testAnonymousAndDisabled(): void
    {
        $this->signOut();
        $this->dispatch();
        $this->assertError('unauthorized', 'unauthorized');

        $this->enabled = false;
        $this->dispatch();
        $this->assertError('POS is disabled.', 'disabled');
    }

    public function testCheckoutErrors(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3];
        $this->checkoutService->method('placeOrder')->willReturnOnConsecutiveCalls(
            $this->throwException($this->localized('Insufficient payment.')),
            $this->throwException(new \RuntimeException('deadlock'))
        );
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('Checkout place failed: deadlock'));

        $this->dispatch();
        $this->assertError('Insufficient payment.');
        $this->dispatch();
        $this->assertError('Unable to place the order. Please try again.');
    }
}
