<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use Panth\MagePos\Model\Hold;
use Panth\MagePos\Model\PaymentMethod;
use Panth\MagePos\Model\PosUser;
use Panth\MagePos\Model\QuickKey;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Model\Role;
use Panth\MagePos\Model\Session;
use Panth\MagePos\Model\UserPreference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    private const ID_FIELDS = [
        Role::class => 'role_id',
        Register::class => 'register_id',
        PaymentMethod::class => 'method_id',
        QuickKey::class => 'quick_key_id',
        Hold::class => 'hold_id',
        UserPreference::class => 'preference_id',
        Session::class => 'session_id',
        PosUser::class => 'user_id',
    ];

    private function make(string $class, array $data = []): object
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn(self::ID_FIELDS[$class]);

        return new $class(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $resource,
            null,
            $data
        );
    }

    public function testPermissionsArrayDecodesStoredJson(): void
    {
        $role = $this->make(Role::class, ['permissions' => '{"can_refund":true,"max_discount_percent":10}']);

        $this->assertSame(['can_refund' => true, 'max_discount_percent' => 10], $role->getPermissionsArray());
    }

    public static function invalidPermissionsProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'invalid json' => ['{oops'],
            'scalar json' => ['42'],
        ];
    }

    #[DataProvider('invalidPermissionsProvider')]
    public function testUnusablePermissionsDecodeToEmptyArray(string $raw): void
    {
        $this->assertSame([], $this->make(Role::class, ['permissions' => $raw])->getPermissionsArray());
    }

    public function testMissingPermissionsDecodeToEmptyArray(): void
    {
        $this->assertSame([], $this->make(Role::class)->getPermissionsArray());
    }

    public function testSetPermissionsArrayEncodesWithoutEscapingSlashesOrUnicode(): void
    {
        $role = $this->make(Role::class);
        $this->assertSame($role, $role->setPermissionsArray(['note' => 'a/b', 'label' => 'Caf' . "\u{e9}"]));

        $this->assertSame('{"note":"a/b","label":"Caf' . "\u{e9}" . '"}', $role->getPermissions());
        $this->assertSame(['note' => 'a/b', 'label' => 'Caf' . "\u{e9}"], $role->getPermissionsArray());
    }

    public function testSetPermissionsArrayFallsBackToEmptyObjectWhenEncodingFails(): void
    {
        $role = $this->make(Role::class);
        $role->setPermissionsArray(['bad' => "\xB1\x31"]);

        $this->assertSame('{}', $role->getPermissions());
    }

    public static function identityProvider(): array
    {
        return [
            [Register::class, 'register_id', 'panth_pos_register_'],
            [PaymentMethod::class, 'method_id', 'panth_pos_payment_method_'],
            [QuickKey::class, 'quick_key_id', 'panth_pos_quick_key_'],
            [Hold::class, 'hold_id', 'panth_pos_hold_'],
            [UserPreference::class, 'preference_id', 'panth_pos_user_preference_'],
        ];
    }

    #[DataProvider('identityProvider')]
    public function testIdentitiesUseCacheTagAndEntityId(string $class, string $idField, string $prefix): void
    {
        $model = $this->make($class, [$idField => '12']);

        $this->assertSame([$prefix . '12'], $model->getIdentities());
        $this->assertSame([$prefix . '0'], $this->make($class)->getIdentities());
    }

    public function testNullableNumericAccessorsCastOrReturnNull(): void
    {
        $session = $this->make(Session::class, ['session_id' => '4', 'opening_float' => '10.50', 'counted_cash' => null]);

        $this->assertSame(4, $session->getSessionId());
        $this->assertSame(10.5, $session->getOpeningFloat());
        $this->assertNull($session->getCountedCash());
        $this->assertNull($session->getClosedAt());

        $user = $this->make(PosUser::class, ['user_id' => '3', 'status' => '1', 'role_id' => null]);
        $this->assertSame(3, $user->getUserId());
        $this->assertSame(1, $user->getStatus());
        $this->assertNull($user->getRoleId());
        $this->assertNull($user->getPinHash());
        $this->assertSame('', $user->getUsername());
    }
}
