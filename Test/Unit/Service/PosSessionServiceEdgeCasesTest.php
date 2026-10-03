<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../PosTestHelperTrait.php';

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\MagePos\Api\CashMovementRepositoryInterface;
use Panth\MagePos\Api\Data\CashMovementInterfaceFactory;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\Data\SessionInterfaceFactory;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Model\CashMovement;
use Panth\MagePos\Model\PosUser;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Model\Session;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\PosSessionService;
use Panth\MagePos\Test\Unit\MagicCallsTrait;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PosSessionServiceEdgeCasesTest extends TestCase
{
    use MagicCallsTrait;
    use PosTestHelperTrait;

    private SessionRepositoryInterface&MockObject $sessionRepository;
    private SessionInterfaceFactory&MockObject $sessionFactory;
    private CashMovementRepositoryInterface&MockObject $movementRepository;
    private CashMovementInterfaceFactory&MockObject $movementFactory;
    private RegisterRepositoryInterface&MockObject $registerRepository;
    private PosUserRepositoryInterface&MockObject $userRepository;
    private AuthService&MockObject $authService;
    private SessionManager&MockObject $sessionManager;
    private array $sessionData = [];
    private array $openSessions = [];
    private array $movements = [];
    private array $savedMovements = [];

    protected function setUp(): void
    {
        $this->sessionData = [];
        $this->openSessions = [];
        $this->movements = [];
        $this->savedMovements = [];
        $this->criteriaFilters = [];
        $this->sessionRepository = $this->createMock(SessionRepositoryInterface::class);
        $this->sessionFactory = $this->createMock(SessionInterfaceFactory::class);
        $this->movementRepository = $this->createMock(CashMovementRepositoryInterface::class);
        $this->movementFactory = $this->createMock(CashMovementInterfaceFactory::class);
        $this->registerRepository = $this->createMock(RegisterRepositoryInterface::class);
        $this->userRepository = $this->createMock(PosUserRepositoryInterface::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->sessionManager = $this->getMockBuilder(SessionManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', '__call'])
            ->getMock();
        $this->sessionManager->method('getData')->willReturnCallback(fn ($k) => $this->sessionData[$k] ?? null);
        $this->stubMagicCalls($this->sessionManager, [
            'setData' => function ($k, $v) {
                $this->sessionData[$k] = $v;
                return $this->sessionManager;
            },
            'unsetData' => function ($k) {
                unset($this->sessionData[$k]);
                return $this->sessionManager;
            },
        ]);
        $this->sessionRepository->method('getList')->willReturnCallback(fn () => $this->makeSearchResults($this->openSessions));
        $this->movementRepository->method('getList')->willReturnCallback(fn () => $this->makeSearchResults($this->movements));
        $this->movementFactory->method('create')->willReturnCallback(fn () => $this->makeModel(CashMovement::class, 'movement_id'));
        $this->movementRepository->method('save')->willReturnCallback(function ($m) {
            $this->savedMovements[] = $m;
            return $m;
        });
        $this->registerRepository->method('getById')->willReturnCallback(function (int $id) {
            if ($id === 99) {
                throw new NoSuchEntityException(__('missing'));
            }
            return $this->makeModel(Register::class, 'register_id', [
                'register_id' => $id, 'name' => 'Till ' . $id, 'code' => 'T' . $id, 'store_id' => 1, 'status' => $id === 5 ? 0 : 1,
            ]);
        });
        $this->userRepository->method('getById')->willReturnCallback(
            fn (int $id) => $this->makeModel(PosUser::class, 'user_id', ['user_id' => $id, 'name' => 'User ' . $id])
        );
    }

    private function makeService(): PosSessionService
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'joinLeft', 'join', 'where', 'group', 'order'] as $m) {
            $select->method($m)->willReturnSelf();
        }
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn(['orders_count' => '2', 'gross' => '30.555']);
        $connection->method('fetchAll')->willReturn([
            ['method_code' => 'cash', 'method_title' => 'Cash', 'amount' => '30.555', 'orders_count' => '2'],
        ]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-01-01 10:00:00');

        return new PosSessionService(
            $this->sessionRepository,
            $this->sessionFactory,
            $this->movementRepository,
            $this->movementFactory,
            $this->registerRepository,
            $this->userRepository,
            $this->authService,
            $this->makeCriteriaBuilder(),
            $this->makeSortOrderBuilder(),
            $this->sessionManager,
            $resource,
            $dateTime
        );
    }

    private function signIn(int $userId = 9): void
    {
        $user = $this->createStub(PosUserInterface::class);
        $user->method('getUserId')->willReturn($userId);
        $this->authService->method('requireUser')->willReturn($user);
        $this->authService->method('getCurrentUser')->willReturn($user);
    }

    private function session(array $data): Session
    {
        return $this->makeModel(Session::class, 'session_id', $data);
    }

    private function movement(string $type, float $amount): CashMovement
    {
        return $this->makeModel(CashMovement::class, 'movement_id', ['type' => $type, 'amount' => $amount, 'session_id' => 7]);
    }

    public function testOpenCreatesSessionRecordsFloatAndRemembersIt(): void
    {
        $this->signIn(9);
        $this->authService->expects($this->once())->method('requirePermission')->with('can_open_close');
        $session = $this->session([]);
        $this->sessionFactory->method('create')->willReturn($session);
        $this->sessionRepository->expects($this->once())->method('save')->with($session)
            ->willReturnCallback(static function (Session $s) {
                $s->setSessionId(7);
                return $s;
            });

        $payload = $this->makeService()->open(2, 100.12345, '');

        $this->assertSame(7, $payload['session_id']);
        $this->assertSame('Till 2', $payload['register_name']);
        $this->assertSame('User 9', $payload['user_name']);
        $this->assertSame('open', $payload['status']);
        $this->assertSame(100.1235, $payload['opening_float']);
        $this->assertNull($payload['note']);
        $this->assertSame('2026-01-01 10:00:00', $payload['opened_at']);
        $this->assertSame(7, $this->sessionData[PosSessionService::SESSION_KEY_SESSION_ID]);
        $this->assertSame('float', $this->savedMovements[0]->getType());
        $this->assertSame(100.1235, $this->savedMovements[0]->getAmount());
        $this->assertContains(['register_id', 2, 'eq'], $this->criteriaFilters);
    }

    public function testOpenRejectsUnknownRegister(): void
    {
        $this->signIn();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Register with id "99" does not exist.');
        $this->makeService()->open(99, 0, null);
    }

    public function testOpenRejectsDisabledRegister(): void
    {
        $this->signIn();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Register "Till 5" is disabled.');
        $this->makeService()->open(5, 0, null);
    }

    public function testOpenRejectsRegisterWithOpenSession(): void
    {
        $this->signIn();
        $this->openSessions = [$this->session(['session_id' => 3, 'status' => 'open'])];
        $this->sessionRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Register "Till 2" already has an open session (#3).');
        $this->makeService()->open(2, 0, null);
    }

    public function testCurrentIsNullForAnonymousUser(): void
    {
        $this->authService->method('getCurrentUser')->willReturn(null);
        $this->sessionRepository->expects($this->never())->method('getById');

        $this->assertNull($this->makeService()->current());
    }

    public function testCurrentUsesRememberedOpenSession(): void
    {
        $this->signIn();
        $this->sessionData[PosSessionService::SESSION_KEY_SESSION_ID] = 7;
        $this->sessionRepository->method('getById')->with(7)->willReturn(
            $this->session(['session_id' => 7, 'register_id' => 2, 'user_id' => 9, 'status' => 'open'])
        );
        $this->movements = [$this->movement('float', 50), $this->movement('sale', 12.5)];

        $payload = $this->makeService()->current();

        $this->assertSame(7, $payload['session_id']);
        $this->assertSame(62.5, $payload['expected_cash']);
    }

    public function testCurrentForgetsClosedSessionAndFindsUsersOpenOne(): void
    {
        $this->signIn(9);
        $this->sessionData[PosSessionService::SESSION_KEY_SESSION_ID] = 7;
        $this->sessionRepository->method('getById')->willReturn($this->session(['session_id' => 7, 'status' => 'closed']));
        $this->openSessions = [$this->session(['session_id' => 8, 'register_id' => 2, 'status' => 'open'])];

        $payload = $this->makeService()->current();

        $this->assertSame(8, $payload['session_id']);
        $this->assertSame(8, $this->sessionData[PosSessionService::SESSION_KEY_SESSION_ID]);
        $this->assertContains(['user_id', 9, 'eq'], $this->criteriaFilters);
    }

    public function testCurrentWithoutAnyOpenSessionClearsStaleId(): void
    {
        $this->signIn(9);
        $this->sessionData[PosSessionService::SESSION_KEY_SESSION_ID] = 7;
        $this->sessionRepository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));

        $this->assertNull($this->makeService()->current());
        $this->assertArrayNotHasKey(PosSessionService::SESSION_KEY_SESSION_ID, $this->sessionData);
    }

    public function testXReportAggregatesMovementsAndOrderTotals(): void
    {
        $this->sessionRepository->method('getById')->with(7)->willReturn(
            $this->session(['session_id' => 7, 'register_id' => 99, 'user_id' => 9, 'status' => 'open', 'opening_float' => 50])
        );
        $this->movements = [
            $this->movement('float', 50),
            $this->movement('sale', 20),
            $this->movement('sale', 5.5),
            $this->movement('refund', -3),
            $this->movement('in', 10),
            $this->movement('out', -7),
            $this->movement('mystery', 1),
        ];

        $report = $this->makeService()->xReport(7);

        $this->assertSame(2, $report['orders_count']);
        $this->assertSame(30.56, $report['gross']);
        $this->assertSame([['method_code' => 'cash', 'method_title' => 'Cash', 'amount' => 30.555, 'orders_count' => 2]], $report['by_payment_method']);
        $this->assertSame([
            'opening_float' => 50.0, 'sales' => 25.5, 'refunds' => -3.0, 'in' => 10.0, 'out' => -7.0,
            'expected' => 76.5, 'counted' => null, 'over_short' => null,
        ], $report['cash']);
        $this->assertCount(7, $report['movements']);
        $this->assertNull($report['session']['register_name']);
        $this->assertSame('2026-01-01 10:00:00', $report['generated_at']);
    }

    public function testXReportOfClosedSessionUsesStoredExpectedCash(): void
    {
        $this->sessionRepository->method('getById')->willReturn($this->session([
            'session_id' => 7, 'status' => 'closed', 'expected_cash' => 80, 'counted_cash' => 79, 'over_short' => -1,
        ]));
        $this->movements = [$this->movement('float', 50)];

        $report = $this->makeService()->xReport(7);

        $this->assertSame(80.0, $report['cash']['expected']);
        $this->assertSame(79.0, $report['cash']['counted']);
        $this->assertSame(-1.0, $report['cash']['over_short']);
        $this->assertSame(80.0, $report['session']['expected_cash']);
    }
}
