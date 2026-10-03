<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../PosTestHelperTrait.php';

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Session\SessionManager;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RoleRepositoryInterface;
use Panth\MagePos\Model\PosUser;
use Panth\MagePos\Model\Role;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Test\Unit\MagicCallsTrait;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AuthServiceEdgeCasesTest extends TestCase
{
    use MagicCallsTrait;
    use PosTestHelperTrait;

    private array $sessionData = [];
    private int $regenerated = 0;

    protected function setUp(): void
    {
        $this->sessionData = [];
        $this->regenerated = 0;
    }

    private function makeService(array $users, array $roles): AuthService
    {
        $sessionManager = $this->getMockBuilder(SessionManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'regenerateId', '__call'])
            ->getMock();
        $sessionManager->method('getData')->willReturnCallback(fn ($key = '') => $this->sessionData[$key] ?? null);
        $sessionManager->method('regenerateId')->willReturnCallback(function () use ($sessionManager) {
            $this->regenerated++;
            return $sessionManager;
        });
        $this->stubMagicCalls($sessionManager, [
            'setData' => function ($key, $value) {
                $this->sessionData[$key] = $value;
            },
            'unsetData' => function ($key) {
                unset($this->sessionData[$key]);
            },
        ]);
        $userRepository = $this->createStub(PosUserRepositoryInterface::class);
        $userRepository->method('getById')->willReturnCallback(static function (int $id) use ($users) {
            return $users[$id] ?? throw new NoSuchEntityException(__('no user'));
        });
        $roleRepository = $this->createStub(RoleRepositoryInterface::class);
        $roleRepository->method('getById')->willReturnCallback(static function (int $id) use ($roles) {
            return $roles[$id] ?? throw new NoSuchEntityException(__('no role'));
        });

        return new AuthService($sessionManager, $userRepository, $roleRepository, $this->makeCriteriaBuilder(), new Json());
    }

    private function user(int $id, ?int $roleId, int $status = 1): PosUser
    {
        return $this->makeModel(PosUser::class, 'user_id', [
            'user_id' => $id, 'name' => 'Ann', 'username' => 'ann', 'role_id' => $roleId, 'status' => $status,
        ]);
    }

    private function role(string $permissions): Role
    {
        return $this->makeModel(Role::class, 'role_id', ['permissions' => $permissions]);
    }

    public function testLockMakesSignedInUserLockedAndRequireUserFail(): void
    {
        $service = $this->makeService([7 => $this->user(7, null)], []);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;

        $this->assertFalse($service->isLocked());
        $service->lock();

        $this->assertTrue($service->isLocked());
        $this->assertTrue($this->sessionData[AuthService::SESSION_KEY_LOCKED]);
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('unauthorized');
        $service->requireUser();
    }

    public function testLockFlagWithoutUserIsNotLocked(): void
    {
        $service = $this->makeService([], []);
        $this->sessionData[AuthService::SESSION_KEY_LOCKED] = true;

        $this->assertFalse($service->isLocked());
    }

    public function testLogoutClearsLockAndRegeneratesSessionId(): void
    {
        $service = $this->makeService([7 => $this->user(7, null)], []);
        $this->sessionData = [AuthService::SESSION_KEY_USER_ID => 7, AuthService::SESSION_KEY_LOCKED => true];

        $service->logout();

        $this->assertSame([], $this->sessionData);
        $this->assertSame(1, $this->regenerated);
        $this->assertNull($service->getCurrentUser());
    }

    public function testBuildUserPayloadNormalisesPermissionFlags(): void
    {
        $service = $this->makeService([], [3 => $this->role('{"can_refund":1,"can_edit_layout":"","max_discount_percent":"150","extra":true}')]);

        $payload = $service->buildUserPayload($this->user(7, 3));

        $this->assertSame(7, $payload['id']);
        $this->assertSame('Ann', $payload['name']);
        $this->assertSame('ann', $payload['username']);
        $this->assertSame(AuthService::PERMISSION_KEYS, array_keys($payload['permissions']));
        $this->assertTrue($payload['permissions']['can_refund']);
        $this->assertFalse($payload['permissions']['can_edit_layout']);
        $this->assertSame(100.0, $payload['max_discount_percent']);
    }

    public function testBuildUserPayloadWithBrokenOrMissingRoleGrantsNothing(): void
    {
        $service = $this->makeService([], [3 => $this->role('{oops'), 4 => $this->role('')]);

        foreach ([$this->user(7, 3), $this->user(7, 4), $this->user(7, 5), $this->user(7, 0)] as $user) {
            $payload = $service->buildUserPayload($user);
            $this->assertSame([], array_filter($payload['permissions']));
            $this->assertSame(0.0, $payload['max_discount_percent']);
        }
    }

    public function testBuildUserPayloadNonNumericDiscountIsZero(): void
    {
        $service = $this->makeService([], [3 => $this->role('{"max_discount_percent":"lots"}')]);

        $this->assertSame(0.0, $service->buildUserPayload($this->user(7, 3))['max_discount_percent']);
    }

    public function testCurrentUserIsCachedAfterFirstLookup(): void
    {
        $service = $this->makeService([7 => $this->user(7, null)], []);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;
        $first = $service->getCurrentUser();
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 8;

        $this->assertSame($first, $service->getCurrentUser());
    }

    public function testMissingUserRecordMeansAnonymous(): void
    {
        $service = $this->makeService([], []);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;

        $this->assertNull($service->getCurrentUser());
        $this->assertFalse($service->hasPermission('can_refund'));
        $this->assertSame(0.0, $service->getMaxDiscountPercent());
    }

    public function testRequirePermissionMessageNamesTheKey(): void
    {
        $service = $this->makeService([7 => $this->user(7, 3)], [3 => $this->role('{"can_refund":false}')]);
        $this->sessionData[AuthService::SESSION_KEY_USER_ID] = 7;

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('You do not have the "can_refund" permission.');
        $service->requirePermission('can_refund');
    }
}
