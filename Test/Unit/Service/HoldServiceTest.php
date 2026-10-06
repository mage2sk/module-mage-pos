<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../PosTestHelperTrait.php';

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManager;
use Panth\MagePos\Api\Data\HoldInterfaceFactory;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\HoldRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Model\Hold;
use Panth\MagePos\Model\Session;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\HoldService;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class HoldServiceTest extends TestCase
{
    use PosTestHelperTrait;

    private HoldRepositoryInterface&MockObject $holdRepository;
    private HoldInterfaceFactory&MockObject $holdFactory;
    private SessionRepositoryInterface&MockObject $sessionRepository;
    private SessionManager&MockObject $sessionManager;
    private AuthService&MockObject $authService;
    private array $openSessions = [];

    protected function setUp(): void
    {
        $this->criteriaFilters = [];
        $this->openSessions = [];
        $this->holdRepository = $this->createMock(HoldRepositoryInterface::class);
        $this->holdFactory = $this->createMock(HoldInterfaceFactory::class);
        $this->sessionRepository = $this->createMock(SessionRepositoryInterface::class);
        $this->sessionManager = $this->createMock(SessionManager::class);
        $this->authService = $this->createMock(AuthService::class);

        $user = $this->createStub(PosUserInterface::class);
        $user->method('getUserId')->willReturn(9);
        $this->authService->method('requireUser')->willReturn($user);
        $this->sessionRepository->method('getList')->willReturnCallback(
            fn () => $this->makeSearchResults($this->openSessions)
        );
    }

    private function makeService(): HoldService
    {
        return new HoldService(
            $this->holdRepository,
            $this->holdFactory,
            $this->sessionRepository,
            $this->sessionManager,
            $this->makeCriteriaBuilder(),
            $this->makeSortOrderBuilder(),
            $this->authService
        );
    }

    private function makeHold(array $data = []): Hold
    {
        return $this->makeModel(Hold::class, 'hold_id', $data);
    }

    private function useSession(int $sessionId, string $status, int $registerId): void
    {
        $this->sessionManager->method('getData')->willReturnCallback(
            static fn ($key) => $key === AuthService::SESSION_KEY_SESSION_ID ? $sessionId : null
        );
        $this->sessionRepository->method('getById')->with($sessionId)->willReturn(
            $this->makeModel(Session::class, 'session_id', [
                'session_id' => $sessionId,
                'status' => $status,
                'register_id' => $registerId,
            ])
        );
    }

    public function testSaveStoresHoldForCurrentRegisterAndReturnsDecodedPayload(): void
    {
        $this->useSession(4, Session::STATUS_OPEN, 2);
        $hold = $this->makeHold();
        $this->holdFactory->method('create')->willReturn($hold);
        $this->holdRepository->expects($this->once())->method('save')->with($hold)
            ->willReturnCallback(static function (Hold $h) {
                $h->setHoldId(31);
                return $h;
            });

        $payload = $this->makeService()->save('  Table 4 ', ' {"items":[{"sku":"A","qty":1}]} ', 12);

        $this->assertSame(31, $payload['hold_id']);
        $this->assertSame(2, $payload['register_id']);
        $this->assertSame(9, $payload['user_id']);
        $this->assertSame('Table 4', $payload['label']);
        $this->assertSame(12, $payload['customer_id']);
        $this->assertSame('{"items":[{"sku":"A","qty":1}]}', $payload['cart_json']);
        $this->assertSame(['items' => [['sku' => 'A', 'qty' => 1]]], $payload['cart']);
    }

    public function testSaveDefaultsLabelAndDropsNonPositiveCustomer(): void
    {
        $this->sessionManager->method('getData')->willReturn(null);
        $hold = $this->makeHold();
        $this->holdFactory->method('create')->willReturn($hold);
        $this->holdRepository->method('save')->willReturnArgument(0);

        $payload = $this->makeService()->save('   ', '{"note":"x"}', 0);

        $this->assertMatchesRegularExpression('/^Hold \d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $payload['label']);
        $this->assertNull($payload['customer_id']);
        $this->assertSame(0, $payload['register_id']);
    }

    public function testSaveFallsBackToUsersOpenSessionRegister(): void
    {
        $this->useSession(4, Session::STATUS_CLOSED, 2);
        $this->openSessions = [$this->makeModel(Session::class, 'session_id', ['register_id' => 6])];
        $hold = $this->makeHold();
        $this->holdFactory->method('create')->willReturn($hold);
        $this->holdRepository->method('save')->willReturnArgument(0);

        $payload = $this->makeService()->save('x', '{"items":[1]}');

        $this->assertSame(6, $payload['register_id']);
        $this->assertContains(['user_id', 9, 'eq'], $this->criteriaFilters);
        $this->assertContains(['status', 'open', 'eq'], $this->criteriaFilters);
    }

    public static function invalidCartProvider(): array
    {
        return [
            'blank' => ['   ', 'Cannot hold an empty cart.'],
            'not json' => ['{nope', 'Invalid cart data.'],
            'scalar' => ['"str"', 'Invalid cart data.'],
            'empty items' => ['{"items":[]}', 'Cannot hold an empty cart - add items first.'],
            'items not list' => ['{"items":"x"}', 'Cannot hold an empty cart - add items first.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCartProvider')]
    public function testSaveRejectsInvalidCart(string $cart, string $message): void
    {
        $this->holdRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);
        $this->makeService()->save('label', $cart);
    }

    public function testAllFiltersByRegisterIncludingGlobalHolds(): void
    {
        $this->useSession(4, Session::STATUS_OPEN, 2);
        $this->holdRepository->method('getList')->willReturn($this->makeSearchResults([
            $this->makeHold(['hold_id' => 2, 'cart_json' => 'broken', 'label' => 'B']),
            new \stdClass(),
            $this->makeHold(['hold_id' => 1, 'cart_json' => '{"a":1}', 'label' => 'A']),
        ]));

        $rows = $this->makeService()->all();

        $this->assertSame([2, 1], array_column($rows, 'hold_id'));
        $this->assertNull($rows[0]['cart']);
        $this->assertSame(['a' => 1], $rows[1]['cart']);
        $this->assertContains(['register_id', [0, 2], 'in'], $this->criteriaFilters);
    }

    public function testAllWithoutRegisterDoesNotFilter(): void
    {
        $this->sessionManager->method('getData')->willReturn(null);
        $this->holdRepository->method('getList')->willReturn($this->makeSearchResults([]));

        $this->assertSame([], $this->makeService()->all());
        $this->assertNotContains('register_id', array_column($this->criteriaFilters, 0));
    }

    public function testRestoreReturnsHoldOfSameRegister(): void
    {
        $this->useSession(4, Session::STATUS_OPEN, 2);
        $this->holdRepository->method('getById')->with(5)
            ->willReturn($this->makeHold(['hold_id' => 5, 'register_id' => 2, 'cart_json' => '{}']));

        $this->assertSame(5, $this->makeService()->restore(5)['hold_id']);
    }

    public function testRestoreAllowsGlobalHold(): void
    {
        $this->useSession(4, Session::STATUS_OPEN, 2);
        $this->holdRepository->method('getById')
            ->willReturn($this->makeHold(['hold_id' => 5, 'register_id' => 0, 'cart_json' => '{}']));

        $this->assertSame(5, $this->makeService()->restore(5)['hold_id']);
    }

    public function testRestoreHidesHoldOfAnotherRegister(): void
    {
        $this->useSession(4, Session::STATUS_OPEN, 2);
        $this->holdRepository->method('getById')
            ->willReturn($this->makeHold(['hold_id' => 5, 'register_id' => 3, 'cart_json' => '{}']));

        $this->expectException(NoSuchEntityException::class);
        $this->makeService()->restore(5);
    }

    public function testDeleteRemovesScopedHold(): void
    {
        $this->useSession(4, Session::STATUS_OPEN, 2);
        $hold = $this->makeHold(['hold_id' => 5, 'register_id' => 2, 'cart_json' => '{}']);
        $this->holdRepository->method('getById')->willReturn($hold);
        $this->holdRepository->expects($this->once())->method('delete')->with($hold);

        $this->makeService()->delete(5);
    }

    public function testDeleteOfForeignRegisterHoldIsRefused(): void
    {
        $this->useSession(4, Session::STATUS_OPEN, 2);
        $this->holdRepository->method('getById')
            ->willReturn($this->makeHold(['hold_id' => 5, 'register_id' => 8, 'cart_json' => '{}']));
        $this->holdRepository->expects($this->never())->method('delete');

        $this->expectException(NoSuchEntityException::class);
        $this->makeService()->delete(5);
    }

    public function testMissingSessionRecordFallsBackToOpenSessionLookup(): void
    {
        $this->sessionManager->method('getData')->willReturn(77);
        $this->sessionRepository->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));
        $this->openSessions = [$this->makeModel(Session::class, 'session_id', ['register_id' => 3])];
        $this->holdRepository->method('getById')
            ->willReturn($this->makeHold(['hold_id' => 5, 'register_id' => 3, 'cart_json' => '{}']));

        $this->assertSame(3, $this->makeService()->restore(5)['register_id']);
    }
}
