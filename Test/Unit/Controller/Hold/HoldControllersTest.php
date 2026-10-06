<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Hold;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Controller\Hold\All;
use Panth\MagePos\Controller\Hold\Remove;
use Panth\MagePos\Controller\Hold\Restore;
use Panth\MagePos\Controller\Hold\Save;
use Panth\MagePos\Service\HoldService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
class HoldControllersTest extends AbstractControllerTestCase
{
    private HoldService&MockObject $holdService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->holdService = $this->createMock(HoldService::class);
    }

    private function dispatch(string $class): void
    {
        (new $class(...[...$this->baseArgs(), $this->holdService]))->execute();
    }

    public static function controllerProvider(): array
    {
        return [[All::class], [Remove::class], [Restore::class], [Save::class]];
    }

    #[DataProvider('controllerProvider')]
    public function testAnonymousRejected(string $class): void
    {
        $this->signOut();

        $this->dispatch($class);

        $this->assertError(self::SESSION_EXPIRED, 'unauthorized');
    }

    #[DataProvider('controllerProvider')]
    public function testLockedTerminalRejected(string $class): void
    {
        $this->signIn();
        $this->authService->method('isLocked')->willReturn(true);

        $this->dispatch($class);

        $this->assertError(self::TERMINAL_LOCKED, 'unauthorized');
        $this->assertTrue($this->result['locked']);
    }

    public function testAllListsHolds(): void
    {
        $this->signIn();
        $this->holdService->method('all')->willReturn([['hold_id' => 1]]);

        $this->dispatch(All::class);

        $this->assertSuccess([['hold_id' => 1]]);
    }

    public function testAllReportsError(): void
    {
        $this->signIn();
        $this->holdService->method('all')->willThrowException($this->localized('Oops.'));

        $this->dispatch(All::class);

        $this->assertError('Oops.');
    }

    public static function idControllerProvider(): array
    {
        return [[Remove::class], [Restore::class]];
    }

    #[DataProvider('idControllerProvider')]
    public function testHoldIdRequired(string $class): void
    {
        $this->signIn();
        $this->body = ['hold_id' => 0];

        $this->dispatch($class);

        $this->assertError('hold_id is required.');
    }

    #[DataProvider('idControllerProvider')]
    public function testMissingHoldIsNotFound(string $class): void
    {
        $this->signIn();
        $this->body = ['hold_id' => 5];
        $exception = new NoSuchEntityException(__('x'));
        $this->holdService->method('delete')->willThrowException($exception);
        $this->holdService->method('restore')->willThrowException($exception);

        $this->dispatch($class);

        $this->assertError('This hold no longer exists.', 'not_found');
    }

    #[DataProvider('idControllerProvider')]
    public function testOtherServiceErrors(string $class): void
    {
        $this->signIn();
        $this->body = ['hold_id' => 5];
        $exception = $this->localized('Denied.');
        $this->holdService->method('delete')->willThrowException($exception);
        $this->holdService->method('restore')->willThrowException($exception);

        $this->dispatch($class);

        $this->assertError('Denied.');
    }

    public function testRemoveSucceeds(): void
    {
        $this->signIn();
        $this->body = ['hold_id' => '5'];
        $this->holdService->expects($this->once())->method('delete')->with(5);

        $this->dispatch(Remove::class);

        $this->assertSuccess(['hold_id' => 5]);
        $this->assertSame('Hold removed.', $this->result['message']);
    }

    public function testRestoreSucceeds(): void
    {
        $this->signIn();
        $this->params = ['hold_id' => '5'];
        $this->holdService->expects($this->once())->method('restore')->with(5)->willReturn(['hold_id' => 5]);

        $this->dispatch(Restore::class);

        $this->assertSuccess(['hold_id' => 5]);
    }

    public function testSaveEncodesArrayCartAndNormalisesCustomer(): void
    {
        $this->signIn();
        $this->body = ['label' => 'T1', 'cart_json' => ['items' => [1]], 'customer_id' => 'abc'];
        $this->holdService->expects($this->once())->method('save')->with('T1', '{"items":[1]}', null)->willReturn(['hold_id' => 2]);

        $this->dispatch(Save::class);

        $this->assertSuccess(['hold_id' => 2]);
        $this->assertSame('Cart held.', $this->result['message']);
    }

    public function testSavePassesStringCartAndCustomer(): void
    {
        $this->signIn();
        $this->body = ['cart_json' => '{"a":1}', 'customer_id' => '4'];
        $this->holdService->expects($this->once())->method('save')->with('', '{"a":1}', 4)->willReturn([]);

        $this->dispatch(Save::class);

        $this->assertSuccess([]);
    }

    public function testSaveReportsError(): void
    {
        $this->signIn();
        $this->holdService->method('save')->willThrowException($this->localized('Cannot hold an empty cart.'));

        $this->dispatch(Save::class);

        $this->assertError('Cannot hold an empty cart.');
    }
}
