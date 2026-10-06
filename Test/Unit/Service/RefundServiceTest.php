<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../PosTestHelperTrait.php';

use Magento\Framework\DB\Select;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Session\SessionManager;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForSkuInterface;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Panth\MagePos\Api\CashMovementRepositoryInterface;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Model\CashMovement;
use Panth\MagePos\Model\CashMovementFactory;
use Panth\MagePos\Model\PaymentMethod;
use Panth\MagePos\Model\PosOrderPayment;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\Collection as MethodCollection;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\CollectionFactory as MethodCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\Collection as PaymentCollection;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\CollectionFactory as PaymentCollectionFactory;
use Panth\MagePos\Model\Session;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\RefundService;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class RefundServiceTest extends TestCase
{
    use PosTestHelperTrait;

    private AuthService&MockObject $authService;
    private SessionManager&MockObject $sessionManager;
    private SessionRepositoryInterface&MockObject $sessionRepository;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private OrderCollectionFactory&MockObject $orderCollectionFactory;
    private CreditmemoFactory&MockObject $creditmemoFactory;
    private CreditmemoManagementInterface&MockObject $creditmemoManagement;
    private RegisterRepositoryInterface&MockObject $registerRepository;
    private CashMovementFactory&MockObject $cashMovementFactory;
    private CashMovementRepositoryInterface&MockObject $cashMovementRepository;
    private ModuleManager&MockObject $moduleManager;
    private LoggerInterface&MockObject $logger;
    private GetSourceItemsBySkuInterface&MockObject $getSourceItems;
    private SourceItemsSaveInterface&MockObject $sourceItemsSave;
    private array $methods = [];
    private array $posPayments = [];
    private array $whereClauses = [];
    private int $scopeSize = 1;
    private array $collectionOrders = [];
    private ?int $posSessionId = null;

    protected function setUp(): void
    {
        $this->whereClauses = [];
        $this->scopeSize = 1;
        $this->collectionOrders = [];
        $this->posPayments = [];
        $this->posSessionId = null;
        $this->authService = $this->createMock(AuthService::class);
        $this->sessionManager = $this->createMock(SessionManager::class);
        $this->sessionRepository = $this->createMock(SessionRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $this->creditmemoFactory = $this->createMock(CreditmemoFactory::class);
        $this->creditmemoManagement = $this->createMock(CreditmemoManagementInterface::class);
        $this->registerRepository = $this->createMock(RegisterRepositoryInterface::class);
        $this->cashMovementFactory = $this->createMock(CashMovementFactory::class);
        $this->cashMovementRepository = $this->createMock(CashMovementRepositoryInterface::class);
        $this->moduleManager = $this->createMock(ModuleManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->getSourceItems = $this->createMock(GetSourceItemsBySkuInterface::class);
        $this->sourceItemsSave = $this->createMock(SourceItemsSaveInterface::class);

        $user = $this->createStub(PosUserInterface::class);
        $user->method('getUserId')->willReturn(9);
        $this->authService->method('requireUser')->willReturn($user);
        $this->sessionManager->method('getData')->willReturnCallback(
            fn ($key) => $key === 'panth_pos_session_id' ? $this->posSessionId : null
        );
        $this->orderCollectionFactory->method('create')->willReturnCallback(fn () => $this->makeOrderCollection());
        $this->cashMovementFactory->method('create')->willReturnCallback(
            fn () => $this->makeModel(CashMovement::class, 'movement_id')
        );
        $this->methods = [
            $this->makeModel(PaymentMethod::class, 'method_id', ['code' => 'cash', 'title' => 'Cash', 'type' => 'cash']),
            $this->makeModel(PaymentMethod::class, 'method_id', ['code' => 'card', 'title' => 'Card', 'type' => 'offline']),
        ];
    }

    private function makeOrderCollection(): OrderCollection
    {
        $select = $this->createStub(Select::class);
        foreach (['joinLeft', 'join', 'order', 'limit'] as $m) {
            $select->method($m)->willReturnSelf();
        }
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->whereClauses[] = [$cond, $value];
            return $select;
        });
        $collection = $this->createStub(OrderCollection::class);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getTable')->willReturnArgument(0);
        $collection->method('getSize')->willReturnCallback(fn () => $this->scopeSize);
        $collection->method('getIterator')->willReturnCallback(fn () => new \ArrayIterator($this->collectionOrders));

        return $collection;
    }

    private function makeService(bool $withMsi = false, ?IsSourceItemManagementAllowedForSkuInterface $managed = null): RefundService
    {
        $methodCollection = $this->createStub(MethodCollection::class);
        $methodCollection->method('getIterator')->willReturnCallback(fn () => new \ArrayIterator($this->methods));
        $methodFactory = $this->createStub(MethodCollectionFactory::class);
        $methodFactory->method('create')->willReturn($methodCollection);
        $paymentCollection = $this->createStub(PaymentCollection::class);
        $paymentCollection->method('getIterator')->willReturnCallback(fn () => new \ArrayIterator($this->posPayments));
        $paymentFactory = $this->createStub(PaymentCollectionFactory::class);
        $paymentFactory->method('create')->willReturn($paymentCollection);

        return new RefundService(
            $this->authService,
            $this->sessionManager,
            $this->sessionRepository,
            $this->orderRepository,
            $this->orderCollectionFactory,
            $this->creditmemoFactory,
            $this->creditmemoManagement,
            $this->registerRepository,
            $methodFactory,
            $paymentFactory,
            $this->cashMovementFactory,
            $this->cashMovementRepository,
            $this->moduleManager,
            $this->logger,
            $withMsi ? $this->getSourceItems : null,
            $withMsi ? $this->sourceItemsSave : null,
            $managed
        );
    }

    private function openSession(int $registerId = 2, string $sourceCode = ''): void
    {
        $this->posSessionId = 40;
        $this->sessionRepository->method('getById')->with(40)->willReturn(
            $this->makeModel(Session::class, 'session_id', ['session_id' => 40, 'status' => 'open', 'register_id' => $registerId])
        );
        $this->registerRepository->method('getById')->willReturn(
            $this->makeModel(Register::class, 'register_id', ['register_id' => $registerId, 'source_code' => $sourceCode])
        );
    }

    private function makeOrder(bool $canRefund = true, array $items = [], array $data = []): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['canCreditmemo', 'getAllVisibleItems'])
            ->getMock();
        $order->setData($data + ['entity_id' => 100, 'increment_id' => '000000100', 'order_currency_code' => 'GBP']);
        $order->method('canCreditmemo')->willReturn($canRefund);
        $order->method('getAllVisibleItems')->willReturn($items);

        return $order;
    }

    private function makeOrderItem(array $data): OrderItem
    {
        $item = $this->getMockBuilder(OrderItem::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $item->setData($data);
        $item->method('getId')->willReturn($data['item_id']);

        return $item;
    }

    private function makeCreditmemoItem(array $data): CreditmemoItem
    {
        $item = $this->getMockBuilder(CreditmemoItem::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $item->setData($data);

        return $item;
    }

    private function makeCreditmemo(float $total, array $items = []): Creditmemo&MockObject
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getGrandTotal')->willReturn($total);
        $creditmemo->method('getAllItems')->willReturn($items);
        $creditmemo->method('getEntityId')->willReturn(500);
        $creditmemo->method('getIncrementId')->willReturn('CM500');

        return $creditmemo;
    }

    public function testSearchRequiresRefundPermission(): void
    {
        $this->authService->method('requirePermission')->with('can_refund')
            ->willThrowException(new AuthorizationException(__('denied')));
        $this->orderCollectionFactory->expects($this->never())->method('create');

        $this->expectException(AuthorizationException::class);
        $this->makeService()->searchOrders('x');
    }

    public function testSearchScopesToRegisterEscapesQueryAndBuildsRows(): void
    {
        $this->openSession(2);
        $orderItem = $this->makeOrderItem([
            'item_id' => 7, 'sku' => 'TEE', 'name' => 'Tee', 'qty_ordered' => 3, 'qty_refunded' => 1,
            'price_incl_tax' => 12, 'row_total_incl_tax' => 36,
        ]);
        $unrefundable = $this->makeOrderItem(['item_id' => 8, 'sku' => 'CAP', 'qty_ordered' => 1, 'qty_refunded' => 1, 'price' => 5, 'row_total' => 5]);
        $order = $this->makeOrder(true, [$orderItem, $unrefundable], [
            'pos_order_id' => 3, 'receipt_number' => 'R1-40-0001', 'customer_email' => 'a@b.co',
            'grand_total' => 36, 'total_refunded' => 12, 'status' => 'complete', 'created_at' => '2026-01-01',
        ]);
        $this->collectionOrders = [$order];
        $this->creditmemoFactory->method('createByOrder')->with($order)->willReturn(
            $this->makeCreditmemo(24, [$this->makeCreditmemoItem(['order_item_id' => 7, 'qty' => 2, 'row_total_incl_tax' => 24])])
        );
        $this->posPayments = [
            $this->makeModel(PosOrderPayment::class, 'payment_id', [
                'method_code' => 'cash', 'method_title' => 'Cash', 'amount' => '40.004', 'reference' => null, 'is_change' => 0,
            ]),
        ];

        $result = $this->makeService()->searchOrders(' 10%_ ');

        $this->assertContains(['pos_order.pos_order_id IS NOT NULL', null], $this->whereClauses);
        $this->assertContains(['pos_order.register_id = ?', 2], $this->whereClauses);
        $this->assertContains([
            'main_table.increment_id LIKE ? OR main_table.customer_email LIKE ? OR pos_order.receipt_number LIKE ?',
            '%10\\%\\_%',
        ], $this->whereClauses);
        $row = $result['orders'][0];
        $this->assertSame(100, $row['order_id']);
        $this->assertSame('R1-40-0001', $row['receipt_number']);
        $this->assertSame('Guest', $row['customer_name']);
        $this->assertTrue($row['can_refund']);
        $this->assertSame(12.0, $row['total_refunded']);
        $this->assertSame(2.0, $row['items'][0]['qty_available']);
        $this->assertSame(24.0, $row['items'][0]['refundable_total']);
        $this->assertSame(12.0, $row['items'][0]['refundable_unit']);
        $this->assertSame(0.0, $row['items'][1]['qty_available']);
        $this->assertSame(0.0, $row['items'][1]['refundable_total']);
        $this->assertSame(5.0, $row['items'][1]['refundable_unit']);
        $this->assertSame([[
            'method_code' => 'cash', 'method_title' => 'Cash', 'amount' => 40.0, 'reference' => null, 'is_change' => 0,
        ]], $row['payments']);
    }

    public function testSearchWithoutSessionOrQueryDoesNotFilterRegister(): void
    {
        $order = $this->makeOrder(false, [], ['customer_firstname' => 'Ann', 'customer_lastname' => 'Lee']);
        $this->collectionOrders = [$order];
        $this->creditmemoFactory->expects($this->never())->method('createByOrder');

        $row = $this->makeService()->searchOrders('  ')['orders'][0];

        $this->assertSame([['pos_order.pos_order_id IS NOT NULL', null]], $this->whereClauses);
        $this->assertSame('Ann Lee', $row['customer_name']);
        $this->assertFalse($row['can_refund']);
        $this->assertSame([], $row['payments']);
    }

    public function testSearchLogsPreviewFailureAndStillListsOrder(): void
    {
        $this->collectionOrders = [$this->makeOrder(true)];
        $this->creditmemoFactory->method('createByOrder')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('boom'));

        $this->assertCount(1, $this->makeService()->searchOrders('')['orders']);
    }

    public function testPreviewOfOrderOutsideScopeIsNotFound(): void
    {
        $this->scopeSize = 0;
        $this->orderRepository->expects($this->never())->method('get');

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('The requested order does not exist.');
        $this->makeService()->preview(100, []);
    }

    public function testPreviewRejectsNonRefundableOrder(): void
    {
        $this->orderRepository->method('get')->willReturn($this->makeOrder(false));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Order 000000100 cannot be refunded.');
        $this->makeService()->preview(100, []);
    }

    public function testPreviewPassesPositiveQtysAndComputesLineAmounts(): void
    {
        $order = $this->makeOrder();
        $this->orderRepository->method('get')->willReturn($order);
        $this->creditmemoFactory->expects($this->once())->method('createByOrder')
            ->with($order, ['qtys' => [7 => 1.0, 9 => 2.5]])
            ->willReturn($this->makeCreditmemo(31.555, [
                $this->makeCreditmemoItem(['order_item_id' => 7, 'qty' => 1, 'row_total_incl_tax' => 12, 'discount_amount' => 2]),
                $this->makeCreditmemoItem([
                    'order_item_id' => 9, 'qty' => 2.5, 'row_total_incl_tax' => 0, 'row_total' => 20, 'tax_amount' => 4,
                    'discount_tax_compensation_amount' => 1,
                ]),
                $this->makeCreditmemoItem(['order_item_id' => 10, 'qty' => 1, 'row_total_incl_tax' => 5, 'discount_amount' => 9]),
                $this->makeCreditmemoItem(['order_item_id' => 0, 'qty' => 1]),
            ]));

        $result = $this->makeService()->preview(100, ['7' => '1', '8' => '0', '9' => 2.5, '11' => -1]);

        $this->assertSame(31.56, $result['refund_total']);
        $this->assertSame('GBP', $result['currency']);
        $this->assertSame([
            7 => ['qty' => 1.0, 'amount' => 10.0],
            9 => ['qty' => 2.5, 'amount' => 25.0],
            10 => ['qty' => 1.0, 'amount' => 0.0],
        ], $result['items']);
    }

    public function testPreviewWithoutQtysRequestsFullCreditmemo(): void
    {
        $order = $this->makeOrder();
        $this->orderRepository->method('get')->willReturn($order);
        $this->creditmemoFactory->expects($this->once())->method('createByOrder')->with($order, [])
            ->willReturn($this->makeCreditmemo(0));

        $this->assertSame([], $this->makeService()->preview(100, [])['items']);
    }

    public function testRefundWithNothingLeftIsRejected(): void
    {
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->creditmemoFactory->method('createByOrder')->willReturn($this->makeCreditmemo(0.004));
        $this->creditmemoManagement->expects($this->never())->method('refund');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('There is nothing left to refund on order 000000100.');
        $this->makeService()->refund(100, [], [], false, '');
    }

    public static function badPaymentsProvider(): array
    {
        return [
            'non array row' => [['x'], 'Invalid refund payment row.'],
            'unknown method' => [[['method_code' => 'gift', 'amount' => 10]], 'Unknown POS payment method "gift".'],
            'blank method' => [[['amount' => 10]], 'Unknown POS payment method "".'],
            'zero amount' => [[['method_code' => 'card', 'amount' => 0]], 'Refund amounts must be greater than zero.'],
            'mismatched total' => [[['method_code' => 'card', 'amount' => 9.98]], 'Refund payments (9.98) must equal the refund total of 10.'],
        ];
    }

    #[DataProvider('badPaymentsProvider')]
    public function testRefundValidatesPaymentRows(array $payments, string $message): void
    {
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->creditmemoFactory->method('createByOrder')->willReturn($this->makeCreditmemo(10));
        $this->creditmemoManagement->expects($this->never())->method('refund');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);
        $this->makeService()->refund(100, [], $payments, false, '');
    }

    public function testCashRefundRequiresOpenSession(): void
    {
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->creditmemoFactory->method('createByOrder')->willReturn($this->makeCreditmemo(10));
        $this->creditmemoManagement->expects($this->never())->method('refund');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('An open register session is required to refund cash.');
        $this->makeService()->refund(100, [], [['method_code' => 'cash', 'amount' => 10]], false, '');
    }

    public function testCardRefundWithRestockAndReasonWithoutSession(): void
    {
        $item = $this->makeCreditmemoItem(['sku' => 'TEE', 'qty' => 1]);
        $creditmemo = $this->makeCreditmemo(10, [$item]);
        $creditmemo->expects($this->once())->method('addComment')->with('POS refund: damaged', false, false);
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->creditmemoFactory->method('createByOrder')->willReturn($creditmemo);
        $this->creditmemoManagement->expects($this->once())->method('refund')->with($creditmemo, true);
        $this->cashMovementRepository->expects($this->never())->method('save');

        $result = $this->makeService()->refund(100, [], [['method_code' => 'card', 'amount' => 10, 'reference' => ' T1 ']], true, ' damaged ');

        $this->assertTrue($item->getBackToStock());
        $this->assertSame([
            'creditmemo_id' => 500,
            'creditmemo_increment_id' => 'CM500',
            'order_id' => 100,
            'order_increment_id' => '000000100',
            'refunded_total' => 10.0,
            'cash_refunded' => 0.0,
            'restocked' => true,
        ], $result);
    }

    public function testSplitRefundRecordsNegativeCashMovement(): void
    {
        $this->openSession(2);
        $creditmemo = $this->makeCreditmemo(30, [$this->makeCreditmemoItem(['sku' => 'TEE', 'qty' => 1])]);
        $creditmemo->expects($this->never())->method('addComment');
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->creditmemoFactory->method('createByOrder')->willReturn($creditmemo);
        $saved = [];
        $this->cashMovementRepository->expects($this->once())->method('save')
            ->willReturnCallback(function ($m) use (&$saved) {
                $saved[] = $m;
                return $m;
            });

        $result = $this->makeService()->refund(100, [], [
            ['method_code' => 'cash', 'amount' => 12.5],
            ['method_code' => 'card', 'amount' => 17.5],
        ], false, '');

        $this->assertSame(12.5, $result['cash_refunded']);
        $this->assertSame(40, $saved[0]->getSessionId());
        $this->assertSame(9, $saved[0]->getUserId());
        $this->assertSame('refund', $saved[0]->getType());
        $this->assertSame(-12.5, $saved[0]->getAmount());
        $this->assertSame('Refund 000000100', $saved[0]->getReason());
        $this->assertSame(100, $saved[0]->getOrderId());
    }

    public function testCashMovementFailureIsLoggedNotThrown(): void
    {
        $this->openSession(2);
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->creditmemoFactory->method('createByOrder')->willReturn($this->makeCreditmemo(5));
        $this->cashMovementRepository->method('save')->willThrowException(new \RuntimeException('db'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('Failed to record refund cash movement'));

        $result = $this->makeService()->refund(100, [], [['method_code' => 'cash', 'amount' => 5]], false, 'x');

        $this->assertSame(5.0, $result['cash_refunded']);
    }

    public function testMsiRestockReturnsQtyToRegisterSourceInsteadOfLegacyStock(): void
    {
        $this->openSession(2, 'shop1');
        $this->moduleManager->method('isEnabled')->willReturn(true);
        $managed = $this->createStub(IsSourceItemManagementAllowedForSkuInterface::class);
        $managed->method('execute')->willReturnCallback(static fn (string $sku) => $sku !== 'SERVICE');
        $tee1 = $this->makeCreditmemoItem(['sku' => 'TEE', 'qty' => 1]);
        $tee2 = $this->makeCreditmemoItem(['sku' => 'TEE', 'qty' => 2]);
        $service = $this->makeCreditmemoItem(['sku' => 'SERVICE', 'qty' => 1]);
        $ghost = $this->makeCreditmemoItem(['sku' => 'GHOST', 'qty' => 1]);
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->creditmemoFactory->method('createByOrder')->willReturn($this->makeCreditmemo(10, [$tee1, $tee2, $service, $ghost]));

        $otherSource = $this->createMock(SourceItemInterface::class);
        $otherSource->method('getSourceCode')->willReturn('default');
        $shopSource = $this->createMock(SourceItemInterface::class);
        $shopSource->method('getSourceCode')->willReturn('shop1');
        $shopSource->method('getQuantity')->willReturn(-1.0);
        $shopSource->expects($this->once())->method('setQuantity')->with(2.0);
        $shopSource->expects($this->once())->method('setStatus')->with(SourceItemInterface::STATUS_IN_STOCK);
        $this->getSourceItems->method('execute')->willReturnCallback(
            static fn (string $sku) => $sku === 'TEE' ? [$otherSource, $shopSource] : []
        );
        $this->sourceItemsSave->expects($this->once())->method('execute')->with([$shopSource]);
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('No source item for SKU GHOST'));

        $this->makeService(true, $managed)->refund(100, [], [], true, '');

        $this->assertFalse($tee1->getBackToStock());
        $this->assertFalse($tee2->getBackToStock());
        $this->assertTrue($service->getBackToStock());
        $this->assertFalse($ghost->getBackToStock());
    }

    public function testRestockFallsBackToLegacyWhenRegisterHasNoSource(): void
    {
        $this->openSession(2, '');
        $this->moduleManager->method('isEnabled')->willReturn(true);
        $item = $this->makeCreditmemoItem(['sku' => 'TEE', 'qty' => 1]);
        $this->orderRepository->method('get')->willReturn($this->makeOrder());
        $this->creditmemoFactory->method('createByOrder')->willReturn($this->makeCreditmemo(10, [$item]));
        $this->sourceItemsSave->expects($this->never())->method('execute');

        $this->makeService(true)->refund(100, [], [], true, '');

        $this->assertTrue($item->getBackToStock());
    }
}
