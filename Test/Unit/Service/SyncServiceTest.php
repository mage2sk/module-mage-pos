<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Panth\MagePos\Api\Data\PosOrderInterface;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\PosOrderRepositoryInterface;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CartService;
use Panth\MagePos\Service\CheckoutService;
use Panth\MagePos\Service\DiscountService;
use Panth\MagePos\Service\SyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SyncServiceTest extends TestCase
{
    private AuthService&MockObject $authService;
    private CartService&MockObject $cartService;
    private DiscountService&MockObject $discountService;
    private CheckoutService&MockObject $checkoutService;
    private PosOrderRepositoryInterface&MockObject $posOrderRepository;
    private OrderRepositoryInterface&MockObject $orderRepository;

    public function testPushOrdersRequiresAnAuthenticatedCashier(): void
    {
        $service = $this->makeService();
        $this->authService->method('requireUser')
            ->willThrowException(new LocalizedException(__('unauthorized')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('unauthorized');
        $service->pushOrders([['client_uuid' => 'uuid-1']]);
    }

    public function testEntryWithoutClientUuidIsRejectedAsInvalid(): void
    {
        $service = $this->makeService();
        $this->signIn();

        $results = $service->pushOrders([['cart' => ['items' => []]]]);

        $this->assertSame(SyncService::STATUS_ERROR, $results['invalid_0']['status']);
        $this->assertSame('Queued order is missing its client_uuid.', $results['invalid_0']['message']);
    }

    public function testNonObjectEntryIsRejectedAsInvalid(): void
    {
        $service = $this->makeService();
        $this->signIn();

        $results = $service->pushOrders(['just-a-string']);

        $this->assertSame(SyncService::STATUS_ERROR, $results['invalid_0']['status']);
    }

    public function testAlreadySyncedUuidIsReportedDuplicateWithItsIncrementId(): void
    {
        $service = $this->makeService();
        $this->signIn();
        $this->givenUuidAlreadySynced(88, '100000005');

        $this->cartService->expects($this->never())->method('create');
        $this->checkoutService->expects($this->never())->method('placeOrder');

        $results = $service->pushOrders([$this->queuedEntry('uuid-1')]);

        $this->assertSame(
            ['status' => SyncService::STATUS_DUPLICATE, 'increment_id' => '100000005'],
            $results['uuid-1']
        );
    }

    public function testSameUuidTwiceInOneBatchIsDedupedWithASingleDbLookup(): void
    {
        $service = $this->makeService();
        $this->signIn();
        $this->givenUuidAlreadySynced(88, '100000005');

        $this->posOrderRepository->expects($this->once())->method('getList');

        $results = $service->pushOrders([
            $this->queuedEntry('uuid-1'),
            $this->queuedEntry('uuid-1'),
        ]);

        $this->assertCount(1, $results);
        $this->assertSame(
            ['status' => SyncService::STATUS_DUPLICATE, 'increment_id' => '100000005'],
            $results['uuid-1']
        );
    }

    public function testEntryWithoutItemsFailsWithoutAbortingTheBatch(): void
    {
        $service = $this->makeService();
        $this->signIn();
        $this->givenUuidNotSynced();

        $results = $service->pushOrders([
            [
                'client_uuid' => 'uuid-empty',
                'cart' => ['items' => []],
                'payments' => [['method_code' => 'cash', 'amount' => 10.0]],
            ],
        ]);

        $this->assertSame(SyncService::STATUS_ERROR, $results['uuid-empty']['status']);
        $this->assertSame('Queued order has no items.', $results['uuid-empty']['message']);
    }

    public function testEntryWithoutPaymentsFailsBeforeAnyQuoteIsCreated(): void
    {
        $service = $this->makeService();
        $this->signIn();
        $this->givenUuidNotSynced();
        $this->cartService->expects($this->never())->method('create');

        $results = $service->pushOrders([
            [
                'client_uuid' => 'uuid-nopay',
                'cart' => ['items' => [['product_id' => 3, 'qty' => 1]]],
                'payments' => [],
            ],
        ]);

        $this->assertSame(SyncService::STATUS_ERROR, $results['uuid-nopay']['status']);
        $this->assertSame('Queued order has no payments.', $results['uuid-nopay']['message']);
    }

    public function testNewUuidIsReplayedAndPlacedWithOfflineSyncFlags(): void
    {
        $service = $this->makeService();
        $this->signIn();
        $this->givenUuidNotSynced();

        $this->cartService->method('create')->willReturn(55);
        $this->cartService->expects($this->once())
            ->method('addProduct')
            ->with(55, 3, 2.0, [])
            ->willReturn(['items' => [['item_id' => 9, 'product_id' => 3, 'sku' => 'shirt']]]);

        $this->cartService->expects($this->never())->method('updateItem');

        $capturedOpts = null;
        $this->checkoutService->expects($this->once())
            ->method('placeOrder')
            ->willReturnCallback(function (int $quoteId, array $payments, array $opts) use (&$capturedOpts): array {
                $this->assertSame(55, $quoteId);
                $this->assertSame([['method_code' => 'cash', 'amount' => 25.0]], $payments);
                $capturedOpts = $opts;
                return ['order_id' => 12, 'increment_id' => '100000009'];
            });

        $results = $service->pushOrders([$this->queuedEntry('uuid-new')]);

        $this->assertSame(
            ['status' => SyncService::STATUS_CREATED, 'increment_id' => '100000009'],
            $results['uuid-new']
        );
        $this->assertTrue($capturedOpts['is_offline_sync']);
        $this->assertSame('uuid-new', $capturedOpts['client_uuid']);
        $this->assertSame('2026-06-10T18:00:00Z', $capturedOpts['created_at']);
    }

    public function testReplayFailureOfOneEntryDoesNotPoisonTheOthers(): void
    {
        $service = $this->makeService();
        $this->signIn();
        $this->givenUuidNotSynced();

        $this->cartService->method('create')->willReturn(55);
        $this->cartService->method('addProduct')
            ->willReturn(['items' => [['item_id' => 9, 'product_id' => 3, 'sku' => 'shirt']]]);
        $this->checkoutService->method('placeOrder')->willReturnCallback(
            static function (int $quoteId, array $payments, array $opts): array {
                if ($opts['client_uuid'] === 'uuid-bad') {
                    throw new LocalizedException(__('The cart is empty.'));
                }
                return ['order_id' => 12, 'increment_id' => '100000010'];
            }
        );

        $results = $service->pushOrders([
            $this->queuedEntry('uuid-bad'),
            $this->queuedEntry('uuid-good'),
        ]);

        $this->assertSame(SyncService::STATUS_ERROR, $results['uuid-bad']['status']);
        $this->assertSame('The cart is empty.', $results['uuid-bad']['message']);
        $this->assertSame(SyncService::STATUS_CREATED, $results['uuid-good']['status']);
        $this->assertSame('100000010', $results['uuid-good']['increment_id']);
    }

    private function queuedEntry(string $uuid): array
    {
        return [
            'client_uuid' => $uuid,
            'cart' => ['items' => [['product_id' => 3, 'qty' => 2]]],
            'payments' => [['method_code' => 'cash', 'amount' => 25.0]],
            'created_at' => '2026-06-10T18:00:00Z',
        ];
    }

    private function signIn(): void
    {
        $this->authService->method('requireUser')
            ->willReturn($this->createMock(PosUserInterface::class));
    }

    private function givenUuidNotSynced(): void
    {
        $results = $this->createMock(SearchResultsInterface::class);
        $results->method('getItems')->willReturn([]);
        $this->posOrderRepository->method('getList')->willReturn($results);
    }

    private function givenUuidAlreadySynced(int $orderId, string $incrementId): void
    {
        $posOrder = $this->createMock(PosOrderInterface::class);
        $posOrder->method('getOrderId')->willReturn($orderId);
        $results = $this->createMock(SearchResultsInterface::class);
        $results->method('getItems')->willReturn([$posOrder]);
        $this->posOrderRepository->method('getList')->willReturn($results);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn($incrementId);
        $this->orderRepository->method('get')->with($orderId)->willReturn($order);
    }

    private function makeService(): SyncService
    {
        $this->authService = $this->createMock(AuthService::class);
        $this->cartService = $this->createMock(CartService::class);
        $this->discountService = $this->createMock(DiscountService::class);
        $this->checkoutService = $this->createMock(CheckoutService::class);
        $this->posOrderRepository = $this->createMock(PosOrderRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        return new SyncService(
            $this->authService,
            $this->cartService,
            $this->discountService,
            $this->checkoutService,
            $this->posOrderRepository,
            $this->orderRepository,
            $searchCriteriaBuilder
        );
    }
}
