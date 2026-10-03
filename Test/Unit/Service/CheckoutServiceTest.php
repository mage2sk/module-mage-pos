<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Panth\MagePos\Api\CashMovementRepositoryInterface;
use Panth\MagePos\Api\Data\RegisterInterface;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\PosOrderPaymentRepositoryInterface;
use Panth\MagePos\Api\PosOrderRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Model\CashMovementFactory;
use Panth\MagePos\Model\Payment\ProcessorPool;
use Panth\MagePos\Model\PaymentMethod;
use Panth\MagePos\Model\PosOrder;
use Panth\MagePos\Model\PosOrderFactory;
use Panth\MagePos\Model\PosOrderPayment;
use Panth\MagePos\Model\PosOrderPaymentFactory;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\CollectionFactory as PaymentMethodCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosOrder\Collection as PosOrderCollection;
use Panth\MagePos\Model\ResourceModel\PosOrder\CollectionFactory as PosOrderCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\Collection as PosOrderPaymentCollection;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\CollectionFactory as PosOrderPaymentCollectionFactory;
use Panth\MagePos\Model\SessionFactory;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CheckoutService;
use Panth\MagePos\Service\QuotePreparer;
use Panth\MagePos\Api\Data\PosUserInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CheckoutServiceTest extends TestCase
{
    private AuthService&MockObject $authService;
    private CartRepositoryInterface&MockObject $quoteRepository;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private PosOrderCollectionFactory&MockObject $posOrderCollectionFactory;
    private PosOrderPaymentCollectionFactory&MockObject $posOrderPaymentCollectionFactory;

    public function testCashOverpaymentYieldsChange(): void
    {
        [$rows, $changeDue] = $this->validate(
            [['method_code' => 'cash', 'amount' => 120.0]],
            100.0
        );

        $this->assertSame(20.0, $changeDue);
        $this->assertCount(1, $rows);
        $this->assertSame('cash', $rows[0]['method_code']);
        $this->assertSame(CheckoutService::TYPE_CASH, $rows[0]['type']);
        $this->assertSame(120.0, $rows[0]['amount']);
    }

    public function testExactCashPaymentHasNoChange(): void
    {
        [, $changeDue] = $this->validate(
            [['method_code' => 'cash', 'amount' => 100.0]],
            100.0
        );

        $this->assertSame(0.0, $changeDue);
    }

    public function testPaymentAmountsAreNormalizedToTwoDecimals(): void
    {
        [$rows, $changeDue] = $this->validate(
            [['method_code' => 'cash', 'amount' => 100.004]],
            100.0
        );

        $this->assertSame(100.0, $rows[0]['amount']);
        $this->assertSame(0.0, $changeDue);
    }

    public function testInsufficientCashIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Insufficient payment/');
        $this->validate([['method_code' => 'cash', 'amount' => 90.0]], 100.0);
    }

    public function testSplitCashPlusCardChangeComesOnlyFromCash(): void
    {
        [$rows, $changeDue] = $this->validate(
            [
                ['method_code' => 'card', 'amount' => 90.0, 'reference' => ' AUTH-1 '],
                ['method_code' => 'cash', 'amount' => 30.0],
            ],
            100.0
        );

        $this->assertSame(20.0, $changeDue);
        $this->assertSame('AUTH-1', $rows[0]['reference']);
        $this->assertNull($rows[1]['reference']);
    }

    public function testNonCashOverpaymentCannotProduceChange(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Change can only be given on cash payments.');
        $this->validate(
            [
                ['method_code' => 'cash', 'amount' => 10.0],
                ['method_code' => 'card', 'amount' => 110.0, 'reference' => 'AUTH-1'],
            ],
            100.0
        );
    }

    public function testCardOnlyPaymentMustEqualTotalExactly(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/must equal the order total/');
        $this->validate(
            [['method_code' => 'card', 'amount' => 90.0, 'reference' => 'AUTH-1']],
            100.0
        );
    }

    public function testExactCardOnlyPaymentIsAcceptedWithoutChange(): void
    {
        [$rows, $changeDue] = $this->validate(
            [['method_code' => 'card', 'amount' => 100.0, 'reference' => 'AUTH-1']],
            100.0
        );

        $this->assertSame(0.0, $changeDue);
        $this->assertSame(CheckoutService::TYPE_OFFLINE, $rows[0]['type']);
    }

    public function testUnknownMethodCodeIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown POS payment method "bitcoin".');
        $this->validate([['method_code' => 'bitcoin', 'amount' => 100.0]], 100.0);
    }

    public function testZeroAmountRowIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Payment amounts must be greater than zero.');
        $this->validate([['method_code' => 'cash', 'amount' => 0.0]], 100.0);
    }

    public function testMissingRequiredReferenceIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('A reference is required for Card Terminal.');
        $this->validate([['method_code' => 'card', 'amount' => 100.0]], 100.0);
    }

    public function testEmptyPaymentListIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('At least one payment is required.');
        $this->validate([], 100.0);
    }

    public function testReceiptNumberIsRegisterCodeSessionIdAndNextSequence(): void
    {
        $service = $this->makeService();

        $collection = $this->createMock(PosOrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(4);
        $this->posOrderCollectionFactory->method('create')->willReturn($collection);

        $register = $this->createMock(RegisterInterface::class);
        $register->method('getCode')->willReturn('main');
        $session = $this->createMock(SessionInterface::class);
        $session->method('getSessionId')->willReturn(12);

        $method = new \ReflectionMethod(CheckoutService::class, 'generateReceiptNumber');
        $method->setAccessible(true);

        $this->assertSame('main-12-5', $method->invoke($service, $register, $session));
    }

    public function testPlaceOrderWithKnownClientUuidReturnsExistingOrderWithoutResubmitting(): void
    {
        $service = $this->makeService();

        $user = $this->createMock(PosUserInterface::class);
        $this->authService->method('requireUser')->willReturn($user);

        $posOrder = $this->createMock(PosOrder::class);
        $posOrder->method('getPosOrderId')->willReturn(33);
        $posOrder->method('getOrderId')->willReturn(501);
        $posOrder->method('getReceiptNumber')->willReturn('main-12-2');
        $posOrder->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => $key === 'receipt_token' ? 'a1b2c3d4' : null
        );

        $posOrderCollection = $this->createMock(PosOrderCollection::class);
        $posOrderCollection->method('addFieldToFilter')->willReturnSelf();
        $posOrderCollection->method('setPageSize')->willReturnSelf();
        $posOrderCollection->method('getFirstItem')->willReturn($posOrder);
        $this->posOrderCollectionFactory->method('create')->willReturn($posOrderCollection);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(501);
        $order->method('getIncrementId')->willReturn('100000007');
        $this->orderRepository->method('get')->with(501)->willReturn($order);

        $tenderRow = $this->createMock(PosOrderPayment::class);
        $tenderRow->method('getIsChange')->willReturn(0);
        $tenderRow->method('getAmount')->willReturn(107.5);
        $changeRow = $this->createMock(PosOrderPayment::class);
        $changeRow->method('getIsChange')->willReturn(1);
        $changeRow->method('getAmount')->willReturn(-7.5);

        $paymentCollection = $this->createMock(PosOrderPaymentCollection::class);
        $paymentCollection->method('addFieldToFilter')->willReturnSelf();
        $paymentCollection->method('getIterator')->willReturn(new \ArrayIterator([$tenderRow, $changeRow]));
        $this->posOrderPaymentCollectionFactory->method('create')->willReturn($paymentCollection);

        $this->quoteRepository->expects($this->never())->method('getActive');

        $result = $service->placeOrder(99, [], ['client_uuid' => ' uuid-abc-1 ']);

        $this->assertSame(
            [
                'order_id' => 501,
                'increment_id' => '100000007',
                'change_due' => 7.5,
                'receipt_number' => 'main-12-2',
                'receipt_token' => 'a1b2c3d4',
                'online' => [],
            ],
            $result
        );
    }

    private function validate(array $payments, float $grandTotal): array
    {
        $service = $this->makeService();
        $methods = [
            'cash' => $this->makeMethod('cash', 'Cash', CheckoutService::TYPE_CASH),
            'card' => $this->makeMethod('card', 'Card Terminal', CheckoutService::TYPE_OFFLINE, 1),
            'payment_link' => $this->makeMethod('payment_link', 'Payment Link', CheckoutService::TYPE_ONLINE),
        ];

        $method = new \ReflectionMethod(CheckoutService::class, 'validatePayments');
        $method->setAccessible(true);

        return $method->invoke($service, $payments, $grandTotal, $methods);
    }

    private function makeMethod(
        string $code,
        string $title,
        string $type,
        int $requiresReference = 0
    ): PaymentMethod&MockObject {
        $method = $this->createMock(PaymentMethod::class);
        $method->method('getCode')->willReturn($code);
        $method->method('getTitle')->willReturn($title);
        $method->method('getType')->willReturn($type);
        $method->method('getRequiresReference')->willReturn($requiresReference);

        return $method;
    }

    private function makeService(): CheckoutService
    {
        $this->authService = $this->createMock(AuthService::class);
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->posOrderCollectionFactory = $this->createMock(PosOrderCollectionFactory::class);
        $this->posOrderPaymentCollectionFactory = $this->createMock(PosOrderPaymentCollectionFactory::class);

        return new CheckoutService(
            $this->authService,
            $this->createMock(Config::class),
            $this->quoteRepository,
            $this->createMock(CartManagementInterface::class),
            $this->orderRepository,
            $this->createMock(InvoiceService::class),
            $this->createMock(TransactionFactory::class),
            $this->createMock(SessionManagerInterface::class),
            $this->createMock(SessionRepositoryInterface::class),
            $this->createMock(SessionFactory::class),
            $this->createMock(RegisterRepositoryInterface::class),
            $this->createMock(PaymentMethodCollectionFactory::class),
            $this->createMock(PosOrderFactory::class),
            $this->createMock(PosOrderRepositoryInterface::class),
            $this->posOrderCollectionFactory,
            $this->createMock(PosOrderPaymentFactory::class),
            $this->createMock(PosOrderPaymentRepositoryInterface::class),
            $this->posOrderPaymentCollectionFactory,
            $this->createMock(CashMovementFactory::class),
            $this->createMock(CashMovementRepositoryInterface::class),
            new ProcessorPool(),
            $this->createMock(QuotePreparer::class),
            $this->createMock(ModuleManager::class),
            $this->createMock(LoggerInterface::class)
        );
    }
}
