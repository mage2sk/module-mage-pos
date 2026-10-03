<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Auth;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Locale\CurrencyInterface;
use Magento\Directory\Model\Currency;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Panth\MagePos\Api\PaymentMethodRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Controller\Auth\Lock;
use Panth\MagePos\Controller\Auth\Login;
use Panth\MagePos\Controller\Auth\Logout;
use Panth\MagePos\Controller\Auth\Pin;
use Panth\MagePos\Controller\Auth\State;
use Panth\MagePos\Model\PaymentMethod;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Service\AttemptLimiter;
use Panth\MagePos\Service\PosSessionService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
class AuthControllersTest extends AbstractControllerTestCase
{
    private AttemptLimiter&MockObject $limiter;
    private RemoteAddress&MockObject $remoteAddress;
    private array $blocked = [];
    private array $failures = [];
    private array $resets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->blocked = [];
        $this->failures = [];
        $this->resets = [];
        $this->limiter = $this->createMock(AttemptLimiter::class);
        $this->limiter->method('isBlocked')->willReturnCallback(
            fn (string $scope, string $subject) => in_array($scope . ':' . $subject, $this->blocked, true)
        );
        $this->limiter->method('registerFailure')->willReturnCallback(function (string $scope, string $subject) {
            $key = $scope . ':' . $subject;
            $this->failures[$key] = ($this->failures[$key] ?? 0) + 1;
            return $this->failures[$key];
        });
        $this->limiter->method('reset')->willReturnCallback(function (string $scope, string $subject) {
            $this->resets[] = $scope . ':' . $subject;
        });
        $this->limiter->method('getLockoutMinutes')->willReturn(15);
        $this->remoteAddress = $this->createMock(RemoteAddress::class);
        $this->remoteAddress->method('getRemoteAddress')->willReturn('10.0.0.1');
    }

    private function login(): void
    {
        (new Login(...[...$this->baseArgs(), $this->limiter, $this->remoteAddress]))->execute();
    }

    private function pin(): void
    {
        (new Pin(...[...$this->baseArgs(), $this->limiter]))->execute();
    }

    public function testLoginSucceedsAndResetsUsernameCounter(): void
    {
        $this->body = ['username' => 'alice', 'password' => 'pw'];
        $this->authService->expects($this->once())->method('login')->with('alice', 'pw')->willReturn(['id' => 1, 'name' => 'Alice']);

        $this->login();

        $this->assertSuccess(['id' => 1, 'name' => 'Alice']);
        $this->assertSame('Welcome, Alice!', $this->result['message']);
        $this->assertSame(['login_user:alice'], $this->resets);
    }

    public function testLoginFailureRegistersUsernameAndAddress(): void
    {
        $this->body = ['username' => 'alice', 'password' => 'bad'];
        $this->authService->method('login')->willThrowException($this->localized('Invalid username or password.'));

        $this->login();

        $this->assertError('Invalid username or password.', 'invalid_credentials');
        $this->assertSame(['login_user:alice' => 1, 'login_ip:10.0.0.1' => 1], $this->failures);
    }

    public function testLoginFailureWithBlankUsernameIsNotCounted(): void
    {
        $this->body = ['username' => '  ', 'password' => ''];
        $this->authService->method('login')->willThrowException($this->localized('Username and password are required.'));

        $this->login();

        $this->assertError('Username and password are required.', 'invalid_credentials');
        $this->assertSame([], $this->failures);
    }

    public function testLoginBlockedByUsername(): void
    {
        $this->blocked = ['login_user:alice'];
        $this->body = ['username' => 'alice', 'password' => 'pw'];
        $this->authService->expects($this->never())->method('login');

        $this->login();

        $this->assertError('Too many failed sign-in attempts. Please try again in 15 minutes.', 'too_many_attempts', 429);
    }

    public function testLoginBlockedByAddressUsesHigherLimit(): void
    {
        $limiter = $this->createMock(AttemptLimiter::class);
        $limiter->method('isBlocked')->willReturnCallback(
            static fn (string $scope, string $subject, int $max = 5) => $scope === 'login_ip' && $max === 20
        );
        $this->limiter = $limiter;
        $this->authService->expects($this->never())->method('login');

        $this->login();

        $this->assertSame('too_many_attempts', $this->result['code']);
    }

    public function testLoginRequiresFormKeyAndEnabledPos(): void
    {
        $this->formKeyValid = false;
        $this->login();
        $this->assertError('Invalid form key.', 'invalid_form_key', 403);

        $this->enabled = false;
        $this->httpCode = null;
        $this->login();
        $this->assertError('POS is disabled.', 'disabled');
    }

    public function testLockAndLogoutRequireSession(): void
    {
        $this->signOut();
        $this->authService->expects($this->never())->method('lock');
        $this->authService->expects($this->never())->method('logout');

        (new Lock(...$this->baseArgs()))->execute();
        $this->assertError('unauthorized', 'unauthorized');
        (new Logout(...$this->baseArgs()))->execute();
        $this->assertError('unauthorized', 'unauthorized');
    }

    public function testLockedTerminalCanStillLogOutAndLock(): void
    {
        $this->signIn();
        $this->authService->method('isLocked')->willReturn(true);
        $this->authService->expects($this->once())->method('lock');
        $this->authService->expects($this->once())->method('logout');

        (new Lock(...$this->baseArgs()))->execute();
        $this->assertSuccess(['locked' => true]);
        (new Logout(...$this->baseArgs()))->execute();
        $this->assertSuccess(null);
        $this->assertSame('You have been signed out.', $this->result['message']);
    }

    public function testPinUnlockSucceeds(): void
    {
        $this->signIn(9);
        $this->authService->method('isLocked')->willReturn(true);
        $this->body = ['username' => 'cashier', 'pin' => '1234'];
        $this->authService->expects($this->once())->method('pinUnlock')->with('cashier', '1234')->willReturn(['id' => 9]);

        $this->pin();

        $this->assertSuccess(['id' => 9]);
        $this->assertSame(['pin_user:9'], $this->resets);
    }

    public function testWrongPinIsCountedPerUser(): void
    {
        $this->signIn(9);
        $this->body = ['username' => 'cashier', 'pin' => '0000'];
        $this->authService->method('pinUnlock')->willThrowException($this->localized('Invalid PIN.'));
        $this->authService->expects($this->never())->method('logout');

        $this->pin();

        $this->assertError('Invalid PIN.', 'invalid_pin');
        $this->assertSame(['pin_user:9' => 1], $this->failures);
    }

    public function testFifthWrongPinSignsOut(): void
    {
        $this->signIn(9);
        $this->failures = ['pin_user:9' => AttemptLimiter::MAX_ATTEMPTS - 1];
        $this->authService->method('pinUnlock')->willThrowException($this->localized('Invalid PIN.'));
        $this->authService->expects($this->once())->method('logout');

        $this->pin();

        $this->assertError('Too many incorrect PIN attempts. Please sign in with your username and password.', 'invalid_pin');
        $this->assertSame(['pin_user:9'], $this->resets);
    }

    public function testBlockedPinSignsOutWithoutTrying(): void
    {
        $this->signIn(9);
        $this->blocked = ['pin_user:9'];
        $this->authService->expects($this->never())->method('pinUnlock');
        $this->authService->expects($this->once())->method('logout');

        $this->pin();

        $this->assertSame('invalid_pin', $this->result['code']);
    }

    private function makeState(?array $currentSession = null, bool $sessionFails = false, string $currencySymbol = '$'): void
    {
        $sessionService = $this->createMock(PosSessionService::class);
        if ($sessionFails) {
            $sessionService->method('current')->willThrowException(new \RuntimeException('x'));
        } else {
            $sessionService->method('current')->willReturn($currentSession);
        }
        $registers = $this->createStub(RegisterRepositoryInterface::class);
        $registers->method('getList')->willReturn($this->makeSearchResults([
            $this->makeModel(Register::class, 'register_id', ['register_id' => 2, 'name' => 'Front', 'code' => 'R1', 'store_id' => 1]),
        ]));
        $methods = $this->createStub(PaymentMethodRepositoryInterface::class);
        $methods->method('getList')->willReturn($this->makeSearchResults([
            $this->makeModel(PaymentMethod::class, 'method_id', [
                'code' => 'cash', 'title' => 'Cash', 'type' => 'cash', 'icon' => 'c', 'requires_reference' => 0,
                'instructions' => null, 'open_drawer' => 1, 'sort_order' => '1',
            ]),
            new \stdClass(),
        ]));
        $currency = $this->createStub(Currency::class);
        $currency->method('getCode')->willReturn('USD');
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCurrentCurrency')->willReturn($currency);
        $store->method('getFrontendName')->willReturn('Main Store');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $zendCurrency = $this->createStub(\Magento\Framework\Currency::class);
        $zendCurrency->method('getSymbol')->willReturn($currencySymbol);
        $localeCurrency = $this->createStub(CurrencyInterface::class);
        $localeCurrency->method('getCurrency')->willReturn($zendCurrency);
        $this->config->method('getIdleLockMinutes')->willReturn(5);
        $this->config->method('isOfflineModeEnabled')->willReturn(true);

        (new State(
            ...[...$this->baseArgs(), $sessionService, $registers, $methods, $this->makeCriteriaBuilder(),
            $this->makeSortOrderBuilder(), $storeManager, $localeCurrency]
        ))->execute();
    }

    public function testStateForSignedInCashier(): void
    {
        $user = $this->signIn(9);
        $this->authService->method('isLocked')->willReturn(false);
        $this->authService->method('buildUserPayload')->with($user)->willReturn(['id' => 9]);

        $this->makeState(['session_id' => 7]);

        $data = $this->result['data'];
        $this->assertTrue($data['authenticated']);
        $this->assertFalse($data['locked']);
        $this->assertSame(['id' => 9], $data['user']);
        $this->assertSame(['session_id' => 7], $data['session']);
        $this->assertSame([['register_id' => 2, 'name' => 'Front', 'code' => 'R1', 'store_id' => 1]], $data['registers']);
        $this->assertSame([[
            'code' => 'cash', 'title' => 'Cash', 'type' => 'cash', 'icon' => 'c', 'requires_reference' => false,
            'instructions' => null, 'open_drawer' => true, 'sort_order' => 1,
        ]], $data['payment_methods']);
        $this->assertSame(
            ['currency_symbol' => '$', 'idle_lock_minutes' => 5, 'offline_enabled' => true, 'store_name' => 'Main Store'],
            $data['config']
        );
    }

    public function testStateForAnonymousVisitorAndSessionFailure(): void
    {
        $this->signOut();
        $this->authService->expects($this->never())->method('buildUserPayload');

        $this->makeState(null, true, '');

        $data = $this->result['data'];
        $this->assertFalse($data['authenticated']);
        $this->assertNull($data['user']);
        $this->assertNull($data['session']);
        $this->assertSame('USD', $data['config']['currency_symbol']);
    }

    public function testStateSurvivesSessionLookupFailure(): void
    {
        $this->signIn();
        $this->makeState(null, true);

        $this->assertNull($this->result['data']['session']);
        $this->assertTrue($this->result['data']['authenticated']);
    }

    public function testStateWhenDisabled(): void
    {
        $this->enabled = false;
        $this->makeState();

        $this->assertError('POS is disabled.', 'disabled');
    }
}
