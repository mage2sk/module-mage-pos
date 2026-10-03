<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Block\Adminhtml\Session;

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../PosTestHelperTrait.php';

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Block\Adminhtml\Session\View;
use Panth\MagePos\Model\PosUser;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Model\ResourceModel\CashMovement\Collection;
use Panth\MagePos\Model\ResourceModel\CashMovement\CollectionFactory;
use Panth\MagePos\Model\Session;
use Panth\MagePos\Service\PosSessionService;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ViewTest extends TestCase
{
    use PosTestHelperTrait;

    private array $params = [];
    private SessionRepositoryInterface&MockObject $sessionRepository;
    private RegisterRepositoryInterface&MockObject $registerRepository;
    private PosUserRepositoryInterface&MockObject $userRepository;
    private CollectionFactory&MockObject $collectionFactory;
    private PosSessionService&MockObject $posSessionService;

    protected function setUp(): void
    {
        $this->installObjectManager();
        $this->params = [];
        $this->sessionRepository = $this->createMock(SessionRepositoryInterface::class);
        $this->registerRepository = $this->createMock(RegisterRepositoryInterface::class);
        $this->userRepository = $this->createMock(PosUserRepositoryInterface::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->posSessionService = $this->createMock(PosSessionService::class);
    }

    protected function tearDown(): void
    {
        $this->resetObjectManager();
    }

    private function makeBlock(): View
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $this->params[$k] ?? $d);
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            static fn ($route, $params = []) => $route . ($params ? '?' . http_build_query($params) : '')
        );
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(static fn ($amount) => '$' . number_format((float) $amount, 2));

        return new View(
            $context,
            $this->sessionRepository,
            $this->registerRepository,
            $this->userRepository,
            $this->collectionFactory,
            $this->posSessionService,
            new Json(),
            $priceCurrency
        );
    }

    private function withSession(array $data): Session
    {
        $session = $this->makeModel(Session::class, 'session_id', $data + ['session_id' => 7]);
        $this->params['session_id'] = '7';
        $this->sessionRepository->method('getById')->with(7)->willReturn($session);

        return $session;
    }

    public function testNoSessionParamMeansNoSession(): void
    {
        $this->sessionRepository->expects($this->never())->method('getById');
        $block = $this->makeBlock();

        $this->assertNull($block->getSession());
        $this->assertFalse($block->isOpen());
        $this->assertNull($block->getRegisterName());
        $this->assertNull($block->getCashierName());
        $this->assertNull($block->getMovementsCollection());
        $this->assertSame([], $block->getMovements());
        $this->assertSame(0, $block->getMovementsTotal());
        $this->assertSame([], $block->getReport());
        $this->assertSame('*/*/forceclose?session_id=0', $block->getForceCloseUrl());
    }

    public function testUnknownSessionIsLoadedOnce(): void
    {
        $this->params = ['session_id' => '9'];
        $this->sessionRepository->expects($this->once())->method('getById')->willThrowException(new NoSuchEntityException(__('x')));
        $block = $this->makeBlock();

        $this->assertNull($block->getSession());
        $this->assertNull($block->getSession());
    }

    public function testOpenSessionDetailsAndLiveReport(): void
    {
        $this->withSession(['status' => 'open', 'register_id' => 2, 'user_id' => 4, 'opening_float' => 100]);
        $this->registerRepository->method('getById')->willReturn(
            $this->makeModel(Register::class, 'register_id', ['name' => 'Front', 'code' => 'R1'])
        );
        $this->userRepository->expects($this->once())->method('getById')->with(4)->willReturn(
            $this->makeModel(PosUser::class, 'user_id', ['name' => 'Ann'])
        );
        $this->posSessionService->expects($this->once())->method('xReport')->with(7)->willReturn([
            'orders_count' => '3', 'gross' => '45.5',
            'by_payment_method' => [['method_code' => 'cash']],
            'cash' => ['sales' => 20, 'in' => 5, 'out' => 2, 'refunds' => 1, 'expected' => 122],
        ]);
        $block = $this->makeBlock();

        $this->assertTrue($block->isOpen());
        $this->assertSame('Front (R1)', $block->getRegisterName());
        $this->assertSame('Ann', $block->getCashierName());
        $this->assertSame('Ann', $block->resolveUserName(4));
        $this->assertSame(3, $block->getOrdersCount());
        $this->assertSame(45.5, $block->getGross());
        $this->assertSame([['method_code' => 'cash']], $block->getPaymentTotals());
        $this->assertSame([
            'opening_float' => 100.0, 'sales' => 20.0, 'refunds' => 1.0, 'in' => 5.0, 'out' => 2.0,
            'expected' => 122.0, 'counted' => null, 'over_short' => null,
        ], $block->getCashSummary());
        $this->assertSame('*/*/forceclose?session_id=7', $block->getForceCloseUrl());
    }

    public function testClosedSessionUsesStoredSnapshot(): void
    {
        $this->withSession([
            'status' => 'closed', 'totals_json' => '{"orders_count":2,"cash":{"counted":50}}',
            'expected_cash' => 51, 'over_short' => -1, 'register_id' => 2,
        ]);
        $this->posSessionService->expects($this->never())->method('xReport');
        $this->registerRepository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));
        $block = $this->makeBlock();

        $this->assertFalse($block->isOpen());
        $this->assertNull($block->getRegisterName());
        $this->assertSame(2, $block->getOrdersCount());
        $summary = $block->getCashSummary();
        $this->assertSame(50.0, $summary['counted']);
        $this->assertSame(51.0, $summary['expected']);
        $this->assertSame(-1.0, $summary['over_short']);
        $this->assertSame([], $block->getPaymentTotals());
    }

    public function testCorruptSnapshotFallsBackToLiveReportAndFailureToEmpty(): void
    {
        $this->withSession(['status' => 'closed', 'totals_json' => '{bad']);
        $this->posSessionService->expects($this->once())->method('xReport')->willThrowException(new \Exception('x'));
        $block = $this->makeBlock();

        $this->assertSame([], $block->getReport());
        $this->assertSame(0, $block->getOrdersCount());
    }

    public function testUnknownUserNameFallsBackToPlaceholder(): void
    {
        $this->userRepository->expects($this->once())->method('getById')->willThrowException(new NoSuchEntityException(__('x')));
        $block = $this->makeBlock();

        $this->assertSame('User #5', $block->resolveUserName(5));
        $this->assertSame('User #5', $block->resolveUserName(5));
        $this->assertNull($block->resolveUserName(0));
    }

    public function testMovementsCollectionIsFilteredAndPaged(): void
    {
        $this->withSession(['status' => 'open']);
        $this->params['p'] = '3';
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('addFieldToFilter')->with('session_id', 7)->willReturnSelf();
        $collection->expects($this->once())->method('setOrder')->with('movement_id', 'ASC')->willReturnSelf();
        $collection->expects($this->once())->method('setPageSize')->with(View::MOVEMENTS_PAGE_SIZE)->willReturnSelf();
        $collection->expects($this->once())->method('setCurPage')->with(3)->willReturnSelf();
        $collection->method('getItems')->willReturn([10 => 'a', 11 => 'b']);
        $collection->method('getSize')->willReturn(42);
        $this->collectionFactory->expects($this->once())->method('create')->willReturn($collection);
        $block = $this->makeBlock();

        $this->assertSame(['a', 'b'], $block->getMovements());
        $this->assertSame(42, $block->getMovementsTotal());
    }

    public function testMoneyFormattingAndUrls(): void
    {
        $block = $this->makeBlock();

        $this->assertSame('-', $block->formatMoney(null));
        $this->assertSame('-', $block->formatMoney(''));
        $this->assertSame('$3.50', $block->formatMoney('3.5'));
        $this->assertSame('*/*/index', $block->getBackUrl());
        $this->assertSame('sales/order/view?order_id=12', $block->getOrderViewUrl(12));
    }
}
