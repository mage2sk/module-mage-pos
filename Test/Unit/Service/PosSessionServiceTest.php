<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Session\SessionManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\MagePos\Api\CashMovementRepositoryInterface;
use Panth\MagePos\Api\Data\CashMovementInterface;
use Panth\MagePos\Api\Data\CashMovementInterfaceFactory;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\Data\RegisterInterface;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\Data\SessionInterfaceFactory;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\PosSessionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PosSessionServiceTest extends TestCase
{
    private const SESSION_ID = 7;
    private const USER_ID = 9;
    private const REGISTER_ID = 3;

    private SessionRepositoryInterface&MockObject $sessionRepository;
    private SessionInterfaceFactory&MockObject $sessionFactory;
    private CashMovementRepositoryInterface&MockObject $cashMovementRepository;
    private CashMovementInterfaceFactory&MockObject $cashMovementFactory;
    private RegisterRepositoryInterface&MockObject $registerRepository;
    private PosUserRepositoryInterface&MockObject $posUserRepository;
    private AuthService&MockObject $authService;
    private SessionManager&MockObject $sessionManager;
    private ResourceConnection&MockObject $resourceConnection;
    private DateTime&MockObject $dateTime;

    public function testExpectedCashIsTheSumOfSignedMovements(): void
    {
        $service = $this->makeService([
            $this->makeMovement(CashMovementInterface::TYPE_FLOAT, 100.0),
            $this->makeMovement(CashMovementInterface::TYPE_SALE, 25.5),
            $this->makeMovement(CashMovementInterface::TYPE_OUT, -10.0),
            $this->makeMovement(CashMovementInterface::TYPE_REFUND, -5.25),
        ]);

        $this->assertSame(110.25, $service->expectedCash(self::SESSION_ID));
    }

    public function testExpectedCashOfSessionWithoutMovementsIsZero(): void
    {
        $service = $this->makeService([]);

        $this->assertSame(0.0, $service->expectedCash(self::SESSION_ID));
    }

    public function testAddMovementRejectsInvalidType(): void
    {
        $service = $this->makeService([]);
        $this->signIn();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid cash movement type "steal". Allowed: in, out.');
        $service->addMovement('steal', 10.0, 'nope');
    }

    public function testAddMovementRejectsNonPositiveAmount(): void
    {
        $service = $this->makeService([]);
        $this->signIn();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The cash movement amount must be greater than zero.');
        $service->addMovement(CashMovementInterface::TYPE_IN, 0.0, 'zero');
    }

    public function testAddMovementStoresCashOutAsNegativeSignedAmount(): void
    {
        $stored = $this->makeMovement(CashMovementInterface::TYPE_OUT, -40.0, 11, 'Till drop');

        $stored->expects($this->once())->method('setAmount')->with(-40.0)->willReturnSelf();
        $stored->expects($this->once())->method('setType')->with(CashMovementInterface::TYPE_OUT)->willReturnSelf();

        $service = $this->makeService([$stored]);
        $this->signIn('can_cash_inout');
        $this->sessionManager->method('getData')->willReturn(self::SESSION_ID);
        $this->sessionRepository->method('getById')->with(self::SESSION_ID)
            ->willReturn($this->makeSession(SessionInterface::STATUS_OPEN));
        $this->cashMovementFactory->method('create')->willReturn($stored);
        $this->cashMovementRepository->method('save')->willReturnArgument(0);

        $result = $service->addMovement(CashMovementInterface::TYPE_OUT, 40.0, 'Till drop');

        $this->assertSame(-40.0, $result['movement']['amount']);
        $this->assertSame('Till drop', $result['movement']['reason']);
        $this->assertSame(-40.0, $result['expected_cash']);
    }

    public function testAddMovementWithoutOpenSessionThrows(): void
    {
        $service = $this->makeService([]);
        $this->signIn('can_cash_inout');
        $this->sessionManager->method('getData')->willReturn(0);
        $emptyResults = $this->createMock(SearchResultsInterface::class);
        $emptyResults->method('getItems')->willReturn([]);
        $this->sessionRepository->method('getList')->willReturn($emptyResults);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('There is no open register session.');
        $service->addMovement(CashMovementInterface::TYPE_IN, 10.0, 'found money');
    }

    public function testOpenRejectsNegativeOpeningFloat(): void
    {
        $service = $this->makeService([]);
        $this->signIn('can_open_close');
        $this->registerRepository->expects($this->never())->method('getById');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The opening float cannot be negative.');
        $service->open(self::REGISTER_ID, -5.0, null);
    }

    public function testCloseComputesExpectedCountedAndOverShort(): void
    {
        $service = $this->makeService([
            $this->makeMovement(CashMovementInterface::TYPE_FLOAT, 100.0),
            $this->makeMovement(CashMovementInterface::TYPE_SALE, 50.0),
            $this->makeMovement(CashMovementInterface::TYPE_OUT, -20.0),
        ]);
        $this->signIn('can_open_close');

        $session = $this->makeSession(SessionInterface::STATUS_OPEN);
        $session->expects($this->once())->method('setStatus')
            ->with(SessionInterface::STATUS_CLOSED)->willReturnSelf();
        $session->expects($this->once())->method('setExpectedCash')->with(130.0)->willReturnSelf();
        $session->expects($this->once())->method('setCountedCash')->with(125.5)->willReturnSelf();
        $session->expects($this->once())->method('setOverShort')->with(-4.5)->willReturnSelf();
        $session->expects($this->once())->method('setTotalsJson')->with($this->isString())->willReturnSelf();
        $session->expects($this->once())->method('setClosedAt')->with('2026-06-11 12:00:00')->willReturnSelf();

        $this->sessionRepository->method('getById')->with(self::SESSION_ID)->willReturn($session);
        $this->sessionRepository->method('save')->willReturnArgument(0);

        $closeMovement = $this->makeMovement(CashMovementInterface::TYPE_CLOSE, -125.5);
        $closeMovement->expects($this->once())->method('setAmount')->with(-125.5)->willReturnSelf();
        $closeMovement->expects($this->once())->method('setType')
            ->with(CashMovementInterface::TYPE_CLOSE)->willReturnSelf();
        $this->cashMovementFactory->method('create')->willReturn($closeMovement);
        $this->cashMovementRepository->method('save')->willReturnArgument(0);

        $this->sessionManager->method('getData')->willReturn(self::SESSION_ID);
        $this->sessionManager->expects($this->once())->method('__call')
            ->with('unsetData', [PosSessionService::SESSION_KEY_SESSION_ID]);

        $report = $service->close(self::SESSION_ID, 125.5, 'evening close');

        $this->assertSame(130.0, $report['cash']['expected']);
        $this->assertSame(125.5, $report['cash']['counted']);
        $this->assertSame(-4.5, $report['cash']['over_short']);
        $this->assertSame(2, $report['orders_count']);
        $this->assertSame(150.0, $report['gross']);
        $this->assertSame(100.0, $report['cash']['opening_float']);
        $this->assertSame(50.0, $report['cash']['sales']);
        $this->assertSame(-20.0, $report['cash']['out']);
        $this->assertCount(3, $report['movements']);
    }

    public function testCloseRejectsAlreadyClosedSession(): void
    {
        $service = $this->makeService([]);
        $this->signIn('can_open_close');
        $this->sessionRepository->method('getById')
            ->willReturn($this->makeSession(SessionInterface::STATUS_CLOSED));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Session #7 is already closed.');
        $service->close(self::SESSION_ID, 100.0, null);
    }

    public function testCloseRejectsNegativeCountedCash(): void
    {
        $service = $this->makeService([]);
        $this->signIn('can_open_close');
        $this->sessionRepository->method('getById')
            ->willReturn($this->makeSession(SessionInterface::STATUS_OPEN));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The counted cash amount cannot be negative.');
        $service->close(self::SESSION_ID, -1.0, null);
    }

    private function signIn(?string $expectedPermission = null): void
    {
        $user = $this->createMock(PosUserInterface::class);
        $user->method('getUserId')->willReturn(self::USER_ID);
        $user->method('getName')->willReturn('Alice');
        $this->authService->method('requireUser')->willReturn($user);
        $this->authService->method('getCurrentUser')->willReturn($user);
        if ($expectedPermission !== null) {
            $this->authService->expects($this->once())
                ->method('requirePermission')->with($expectedPermission);
        }
    }

    private function makeService(array $movements): PosSessionService
    {
        $this->sessionRepository = $this->createMock(SessionRepositoryInterface::class);
        $this->sessionFactory = $this->createMock(SessionInterfaceFactory::class);
        $this->cashMovementRepository = $this->createMock(CashMovementRepositoryInterface::class);
        $this->cashMovementFactory = $this->createMock(CashMovementInterfaceFactory::class);
        $this->registerRepository = $this->createMock(RegisterRepositoryInterface::class);
        $this->posUserRepository = $this->createMock(PosUserRepositoryInterface::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtDate')->willReturn('2026-06-11 12:00:00');

        $this->sessionManager = $this->getMockBuilder(SessionManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', '__call'])
            ->getMock();

        $movementResults = $this->createMock(SearchResultsInterface::class);
        $movementResults->method('getItems')->willReturn($movements);
        $this->cashMovementRepository->method('getList')->willReturn($movementResults);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('addSortOrder')->willReturnSelf();
        $searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $searchCriteriaBuilder->method('setCurrentPage')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $sortOrderBuilder->method('setField')->willReturnSelf();
        $sortOrderBuilder->method('setAscendingDirection')->willReturnSelf();
        $sortOrderBuilder->method('setDescendingDirection')->willReturnSelf();
        $sortOrderBuilder->method('create')->willReturn($this->createMock(SortOrder::class));

        $select = $this->createMock(Select::class);
        foreach (['from', 'joinLeft', 'join', 'where', 'group', 'order'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn(['orders_count' => '2', 'gross' => '150.0000']);
        $connection->method('fetchAll')->willReturn([]);
        $this->resourceConnection->method('getConnection')->willReturn($connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        $register = $this->createMock(RegisterInterface::class);
        $register->method('getName')->willReturn('Main Register');
        $register->method('getCode')->willReturn('main');
        $register->method('getStoreId')->willReturn(1);
        $this->registerRepository->method('getById')->willReturn($register);

        $posUser = $this->createMock(PosUserInterface::class);
        $posUser->method('getName')->willReturn('Alice');
        $this->posUserRepository->method('getById')->willReturn($posUser);

        return new PosSessionService(
            $this->sessionRepository,
            $this->sessionFactory,
            $this->cashMovementRepository,
            $this->cashMovementFactory,
            $this->registerRepository,
            $this->posUserRepository,
            $this->authService,
            $searchCriteriaBuilder,
            $sortOrderBuilder,
            $this->sessionManager,
            $this->resourceConnection,
            $this->dateTime
        );
    }

    private function makeSession(string $status): SessionInterface&MockObject
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getSessionId')->willReturn(self::SESSION_ID);
        $session->method('getRegisterId')->willReturn(self::REGISTER_ID);
        $session->method('getUserId')->willReturn(self::USER_ID);
        $session->method('getStatus')->willReturn($status);
        $session->method('getOpeningFloat')->willReturn(100.0);
        $session->method('getExpectedCash')->willReturn(null);
        $session->method('getCountedCash')->willReturn(null);
        $session->method('getOverShort')->willReturn(null);
        $session->method('getOpenedAt')->willReturn('2026-06-11 08:00:00');
        $session->method('getClosedAt')->willReturn(null);
        $session->method('getNote')->willReturn(null);
        $session->method('setNote')->willReturnSelf();

        return $session;
    }

    private function makeMovement(
        string $type,
        float $amount,
        int $movementId = 1,
        ?string $reason = null
    ): CashMovementInterface&MockObject {
        $movement = $this->createMock(CashMovementInterface::class);
        $movement->method('getMovementId')->willReturn($movementId);
        $movement->method('getSessionId')->willReturn(self::SESSION_ID);
        $movement->method('getUserId')->willReturn(self::USER_ID);
        $movement->method('getType')->willReturn($type);
        $movement->method('getAmount')->willReturn($amount);
        $movement->method('getReason')->willReturn($reason);
        $movement->method('getOrderId')->willReturn(null);
        $movement->method('getCreatedAt')->willReturn(null);
        $movement->method('setSessionId')->willReturnSelf();
        $movement->method('setUserId')->willReturnSelf();
        $movement->method('setReason')->willReturnSelf();

        return $movement;
    }
}
