<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Session\SessionManager;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\Data\RoleInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RoleRepositoryInterface;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Test\Unit\MagicCallsTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AuthServiceTest extends TestCase
{
    use MagicCallsTrait;

    private array $sessionData = [];

    protected function setUp(): void
    {
        $this->sessionData = [];
    }

    public function testLoginWithValidPasswordReturnsPayloadAndStoresUserIdInSession(): void
    {
        $user = $this->makeUser(7, 'alice', password_hash('s3cret!', PASSWORD_DEFAULT), null, 1, 5);
        $service = $this->makeService(
            [$user],
            [7 => $user],
            [5 => ['can_refund' => true, 'can_price_override' => false, 'max_discount_percent' => 15]]
        );

        $payload = $service->login('  alice ', 's3cret!');

        $this->assertSame(7, $payload['id']);
        $this->assertSame('alice', $payload['username']);
        $this->assertTrue($payload['permissions']['can_refund']);
        $this->assertFalse($payload['permissions']['can_price_override']);

        $this->assertFalse($payload['permissions']['can_view_reports']);
        $this->assertSame(15.0, $payload['max_discount_percent']);
        $this->assertSame(7, $this->sessionData[AuthService::SESSION_KEY_USER_ID]);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $user = $this->makeUser(7, 'alice', password_hash('right-password', PASSWORD_DEFAULT), null, 1, null);
        $service = $this->makeService([$user]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid username or password.');
        $service->login('alice', 'wrong-password');
    }

    public function testLoginRejectsUnknownUserWithSameMessageAsWrongPassword(): void
    {
        $service = $this->makeService([]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid username or password.');
        $service->login('ghost', 'whatever');
    }

    public function testLoginRejectsDisabledAccountEvenWithCorrectPassword(): void
    {
        $user = $this->makeUser(7, 'alice', password_hash('s3cret!', PASSWORD_DEFAULT), null, 0, null);
        $service = $this->makeService([$user]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This account is disabled.');
        $service->login('alice', 's3cret!');
    }

    public function testLoginRequiresUsernameAndPassword(): void
    {
        $service = $this->makeService([]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Username and password are required.');
        $service->login('   ', '');
    }

    public function testPinUnlockVerifiesPinOfSignedInCashierCaseInsensitively(): void
    {
        $user = $this->makeUser(7, 'alice', password_hash('x', PASSWORD_DEFAULT), password_hash('1234', PASSWORD_DEFAULT), 1, null);
        $service = $this->makeService([], [7 => $user]);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;

        $payload = $service->pinUnlock('ALICE', '1234');

        $this->assertSame(7, $payload['id']);
        $this->assertSame('alice', $payload['username']);
    }

    public function testPinUnlockRejectsWrongPin(): void
    {
        $user = $this->makeUser(7, 'alice', password_hash('x', PASSWORD_DEFAULT), password_hash('1234', PASSWORD_DEFAULT), 1, null);
        $service = $this->makeService([], [7 => $user]);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid PIN.');
        $service->pinUnlock('alice', '9999');
    }

    public function testPinUnlockRejectsUserWithoutPinHash(): void
    {
        $user = $this->makeUser(7, 'alice', password_hash('x', PASSWORD_DEFAULT), null, 1, null);
        $service = $this->makeService([], [7 => $user]);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid PIN.');
        $service->pinUnlock('alice', '1234');
    }

    public function testPinUnlockOnlyReauthenticatesTheSignedInCashier(): void
    {
        $user = $this->makeUser(7, 'alice', password_hash('x', PASSWORD_DEFAULT), password_hash('1234', PASSWORD_DEFAULT), 1, null);
        $service = $this->makeService([], [7 => $user]);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('PIN unlock is only available for the signed-in cashier.');
        $service->pinUnlock('bob', '1234');
    }

    public function testPinUnlockWithoutLiveSessionDemandsFullLogin(): void
    {
        $service = $this->makeService([]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Your session has expired. Please sign in with username and password.');
        $service->pinUnlock('alice', '1234');
    }

    public function testLogoutClearsSessionAndCurrentUser(): void
    {
        $user = $this->makeUser(7, 'alice', password_hash('x', PASSWORD_DEFAULT), null, 1, null);
        $service = $this->makeService([], [7 => $user]);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;

        $this->assertNotNull($service->getCurrentUser());
        $service->logout();

        $this->assertArrayNotHasKey(AuthService::SESSION_KEY_USER_ID, $this->sessionData);
        $this->assertNull($service->getCurrentUser());
    }

    public function testRequireUserThrowsUnauthorizedWhenAnonymous(): void
    {
        $service = $this->makeService([]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('unauthorized');
        $service->requireUser();
    }

    public function testGetCurrentUserIgnoresDisabledAccountLeftInSession(): void
    {
        $user = $this->makeUser(7, 'alice', password_hash('x', PASSWORD_DEFAULT), null, 0, null);
        $service = $this->makeService([], [7 => $user]);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;

        $this->assertNull($service->getCurrentUser());
    }

    public function testMaxDiscountPercentIsClampedToOneHundred(): void
    {
        $service = $this->signedInService(['max_discount_percent' => 250]);

        $this->assertSame(100.0, $service->getMaxDiscountPercent());
    }

    public function testMaxDiscountPercentNonNumericFallsBackToZero(): void
    {
        $service = $this->signedInService(['max_discount_percent' => 'lots']);

        $this->assertSame(0.0, $service->getMaxDiscountPercent());
    }

    public function testMaxDiscountPercentNegativeIsClampedToZero(): void
    {
        $service = $this->signedInService(['max_discount_percent' => -5]);

        $this->assertSame(0.0, $service->getMaxDiscountPercent());
    }

    public function testHasPermissionIsFalseWithoutRole(): void
    {
        $user = $this->makeUser(7, 'alice', password_hash('x', PASSWORD_DEFAULT), null, 1, null);
        $service = $this->makeService([], [7 => $user]);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;

        $this->assertFalse($service->hasPermission('can_refund'));
    }

    public function testRequirePermissionThrowsWhenRoleDeniesKey(): void
    {
        $service = $this->signedInService(['can_refund' => false, 'max_discount_percent' => 10]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('You do not have the "can_refund" permission.');
        $service->requirePermission('can_refund');
    }

    public function testRequirePermissionPassesWhenRoleGrantsKey(): void
    {
        $service = $this->signedInService(['can_refund' => true]);

        $service->requirePermission('can_refund');
        $this->assertTrue($service->hasPermission('can_refund'));
    }

    private function signedInService(array $permissions): AuthService
    {
        $user = $this->makeUser(7, 'alice', password_hash('x', PASSWORD_DEFAULT), null, 1, 5);
        $service = $this->makeService([], [7 => $user], [5 => $permissions]);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;

        return $service;
    }

    private function makeUser(
        int $id,
        string $username,
        string $passwordHash,
        ?string $pinHash,
        int $status,
        ?int $roleId
    ): PosUserInterface {
        $user = $this->createMock(PosUserInterface::class);
        $user->method('getUserId')->willReturn($id);
        $user->method('getUsername')->willReturn($username);
        $user->method('getName')->willReturn('Cashier ' . $username);
        $user->method('getPasswordHash')->willReturn($passwordHash);
        $user->method('getPinHash')->willReturn($pinHash);
        $user->method('getStatus')->willReturn($status);
        $user->method('getRoleId')->willReturn($roleId);
        $user->method('setLastLoginAt')->willReturnSelf();

        return $user;
    }

    private function makeService(
        array $usersByUsernameLookup,
        array $usersById = [],
        array $rolePermissions = []
    ): AuthService {
        $sessionManager = $this->getMockBuilder(SessionManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'regenerateId', '__call'])
            ->getMock();
        $sessionManager->method('getData')->willReturnCallback(
            fn ($key = '', $clear = false) => $this->sessionData[$key] ?? null
        );
        $this->stubMagicCalls($sessionManager, [
            'setData' => function ($key, $value) {
                $this->sessionData[$key] = $value;
            },
            'unsetData' => function ($key) {
                unset($this->sessionData[$key]);
            },
        ]);
        $sessionManager->method('regenerateId')->willReturnSelf();

        $results = $this->createMock(SearchResultsInterface::class);
        $results->method('getItems')->willReturn($usersByUsernameLookup);

        $userRepository = $this->createMock(PosUserRepositoryInterface::class);
        $userRepository->method('getList')->willReturn($results);
        $userRepository->method('save')->willReturnArgument(0);
        $userRepository->method('getById')->willReturnCallback(
            static function (int $id) use ($usersById): PosUserInterface {
                if (!isset($usersById[$id])) {
                    throw new NoSuchEntityException(__('no such user %1', $id));
                }
                return $usersById[$id];
            }
        );

        $roleRepository = $this->createMock(RoleRepositoryInterface::class);
        $roleRepository->method('getById')->willReturnCallback(
            function (int $id) use ($rolePermissions): RoleInterface {
                if (!isset($rolePermissions[$id])) {
                    throw new NoSuchEntityException(__('no such role %1', $id));
                }
                $role = $this->createMock(RoleInterface::class);
                $role->method('getPermissions')->willReturn((string)json_encode($rolePermissions[$id]));
                return $role;
            }
        );

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('unserialize')->willReturnCallback(
            static fn (string $json) => json_decode($json, true)
        );

        return new AuthService(
            $sessionManager,
            $userRepository,
            $roleRepository,
            $searchCriteriaBuilder,
            $serializer
        );
    }
}
