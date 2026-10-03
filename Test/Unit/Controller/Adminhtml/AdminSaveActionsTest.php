<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Adminhtml;

require_once __DIR__ . '/AbstractAdminControllerTestCase.php';

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\MagePos\Api\CashMovementRepositoryInterface;
use Panth\MagePos\Api\Data\CashMovementInterfaceFactory;
use Panth\MagePos\Api\PaymentMethodRepositoryInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\QuickKeyRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\RoleRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Controller\Adminhtml;
use Panth\MagePos\Model\CashMovement;
use Panth\MagePos\Model\PaymentMethod;
use Panth\MagePos\Model\PaymentMethodFactory;
use Panth\MagePos\Model\PosUser;
use Panth\MagePos\Model\PosUserFactory;
use Panth\MagePos\Model\QuickKey;
use Panth\MagePos\Model\QuickKeyFactory;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Model\RegisterFactory;
use Panth\MagePos\Model\Role;
use Panth\MagePos\Model\RoleFactory;
use Panth\MagePos\Model\Session;
use Panth\MagePos\Service\PosSessionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;

#[AllowMockObjectsWithoutExpectations]
class AdminSaveActionsTest extends AbstractAdminControllerTestCase
{
    private function methodSave(?PaymentMethod $existing = null, ?\Throwable $saveError = null): array
    {
        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $new = $this->makeModel(PaymentMethod::class, 'method_id');
        $factory = $this->createStub(PaymentMethodFactory::class);
        $factory->method('create')->willReturn($new);
        if ($existing !== null) {
            $repository->method('getById')->willReturn($existing);
        } else {
            $repository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));
        }
        if ($saveError !== null) {
            $repository->method('save')->willThrowException($saveError);
        } else {
            $repository->method('save')->willReturnCallback(static function ($m) {
                $m->setMethodId(12);
                return $m;
            });
        }
        (new Adminhtml\Method\Save($this->context, $repository, $factory))->execute();

        return [$new, $repository];
    }

    public function testMethodSaveWithoutPostDataRedirectsToGrid(): void
    {
        $this->methodSave();

        $this->assertRedirect('*/*/');
        $this->assertMessages([]);
    }

    public static function invalidMethodProvider(): array
    {
        return [
            'bad code' => [['code' => 'Bad Code!', 'title' => 'T', 'type' => 'cash'],
                'Method code is required and may only contain letters, numbers, underscores and dashes.'],
            'leading dash' => [['code' => '-cash', 'title' => 'T', 'type' => 'cash'],
                'Method code is required and may only contain letters, numbers, underscores and dashes.'],
            'no title' => [['code' => 'cash', 'title' => '  ', 'type' => 'cash'], 'Method title is required.'],
            'bad type' => [['code' => 'cash', 'title' => 'Cash', 'type' => 'crypto'], 'Invalid method type. Allowed: cash, offline, online.'],
        ];
    }

    #[DataProvider('invalidMethodProvider')]
    public function testMethodSaveValidation(array $post, string $message): void
    {
        $this->post = $post;
        $this->methodSave();
        $this->assertMessages([['error', $message]]);
        $this->assertRedirect('*/*/new');
    }

    public function testMethodSaveValidationOnExistingRedirectsToEdit(): void
    {
        $this->post = ['method_id' => '3', 'code' => '', 'title' => 'x', 'type' => 'cash'];
        $this->methodSave();

        $this->assertRedirect('*/*/edit', ['id' => 3]);
    }

    public function testMethodSaveNormalisesAndPersistsNewMethod(): void
    {
        $this->post = [
            'code' => ' CARD_1 ', 'title' => ' Card ', 'type' => 'offline', 'is_active' => '0', 'sort_order' => '5',
            'icon' => ' ', 'requires_reference' => '1', 'instructions' => ' Swipe ', 'open_drawer' => '0',
            'payment_url_template' => '',
        ];
        $this->params = ['back' => 1];

        [$method] = $this->methodSave();

        $this->assertSame('card_1', $method->getCode());
        $this->assertSame('Card', $method->getTitle());
        $this->assertSame(0, $method->getIsActive());
        $this->assertSame(5, $method->getSortOrder());
        $this->assertNull($method->getIcon());
        $this->assertSame('Swipe', $method->getInstructions());
        $this->assertNull($method->getPaymentUrlTemplate());
        $this->assertMessages([['success', 'The POS payment method has been saved.']]);
        $this->assertRedirect('*/*/edit', ['id' => 12]);
    }

    public function testMethodSaveOfVanishedMethod(): void
    {
        $this->post = ['method_id' => 3, 'code' => 'cash', 'title' => 'Cash', 'type' => 'cash'];
        $this->methodSave();

        $this->assertMessages([['error', 'This POS payment method no longer exists.']]);
        $this->assertRedirect('*/*/');
    }

    public function testMethodSaveFailureReturnsToForm(): void
    {
        $this->post = ['method_id' => 3, 'code' => 'cash', 'title' => 'Cash', 'type' => 'cash'];
        $existing = $this->makeModel(PaymentMethod::class, 'method_id', ['method_id' => 3]);
        $this->methodSave($existing, new CouldNotSaveException(__('Duplicate code')));

        $this->assertMessages([['error', 'Duplicate code']]);
        $this->assertRedirect('*/*/edit', ['id' => 3]);
    }

    private function quickkeySave(bool $productExists = true): ?QuickKey
    {
        $repository = $this->createStub(QuickKeyRepositoryInterface::class);
        $new = $this->makeModel(QuickKey::class, 'quick_key_id');
        $repository->method('save')->willReturnCallback(static function ($q) {
            $q->setQuickKeyId(6);
            return $q;
        });
        $factory = $this->createStub(QuickKeyFactory::class);
        $factory->method('create')->willReturn($new);
        $products = $this->createStub(ProductRepositoryInterface::class);
        if ($productExists) {
            $products->method('getById')->willReturn($this->createStub(ProductInterface::class));
        } else {
            $products->method('getById')->willThrowException(new NoSuchEntityException(__('x')));
        }
        (new Adminhtml\Quickkey\Save($this->context, $repository, $factory, $products))->execute();

        return $new;
    }

    public function testQuickkeySaveValidatesProductAndColour(): void
    {
        $this->post = ['product_id' => '0'];
        $this->quickkeySave();
        $this->assertMessages([['error', 'A numeric product ID greater than zero is required.']]);
        $this->assertRedirect('*/*/new');

        $this->messages = [];
        $this->post = ['product_id' => '5', 'quick_key_id' => 2];
        $this->quickkeySave(false);
        $this->assertMessages([['error', 'Product ID 5 does not exist.']]);
        $this->assertRedirect('*/*/edit', ['id' => 2]);

        $this->messages = [];
        $this->post = ['product_id' => '5', 'color' => 'blue'];
        $this->quickkeySave();
        $this->assertMessages([['error', 'The colour must be a hex value such as #2563eb.']]);
    }

    public function testQuickkeySaveNormalisesValues(): void
    {
        $this->post = ['product_id' => '5', 'color' => '#ABC', 'register_id' => '-1', 'page' => '0', 'position' => '3', 'label' => ' '];

        $key = $this->quickkeySave();

        $this->assertNull($key->getRegisterId());
        $this->assertSame(5, $key->getProductId());
        $this->assertSame('#ABC', $key->getColor());
        $this->assertNull($key->getLabel());
        $this->assertSame(1, $key->getPage());
        $this->assertSame(3, $key->getPosition());
        $this->assertMessages([['success', 'The POS quick key has been saved.']]);
        $this->assertRedirect('*/*/');
    }

    private function registerSave(?\Throwable $saveError = null): Register
    {
        $repository = $this->createStub(RegisterRepositoryInterface::class);
        $new = $this->makeModel(Register::class, 'register_id');
        $factory = $this->createStub(RegisterFactory::class);
        $factory->method('create')->willReturn($new);
        if ($saveError) {
            $repository->method('save')->willThrowException($saveError);
        } else {
            $repository->method('save')->willReturnCallback(static function ($r) {
                $r->setRegisterId(4);
                return $r;
            });
        }
        (new Adminhtml\Register\Save($this->context, $repository, $factory))->execute();

        return $new;
    }

    public function testRegisterSaveRequiresNameAndCode(): void
    {
        $this->post = ['name' => ' ', 'code' => 'R1'];
        $this->registerSave();
        $this->assertMessages([['error', 'The register name is required.']]);
        $this->assertRedirect('*/*/new');

        $this->messages = [];
        $this->post = ['name' => 'Front', 'code' => ''];
        $this->registerSave();
        $this->assertMessages([['error', 'The register code is required.']]);
    }

    public function testRegisterSaveHydratesAndTruncates(): void
    {
        $this->post = [
            'name' => str_repeat('n', 300), 'code' => str_repeat('c', 70), 'store_id' => '2', 'status' => '0',
            'receipt_header' => ' Hi ', 'receipt_footer' => '', 'source_code' => ' shop1 ',
        ];
        $this->params = ['back' => '1'];

        $register = $this->registerSave();

        $this->assertSame(255, strlen($register->getName()));
        $this->assertSame(64, strlen($register->getCode()));
        $this->assertSame(2, $register->getStoreId());
        $this->assertSame(0, $register->getStatus());
        $this->assertSame('Hi', $register->getReceiptHeader());
        $this->assertNull($register->getReceiptFooter());
        $this->assertSame('shop1', $register->getSourceCode());
        $this->assertRedirect('*/*/edit', ['register_id' => 4]);
    }

    public function testRegisterSaveUnexpectedFailure(): void
    {
        $this->post = ['name' => 'Front', 'code' => 'R1'];
        $this->registerSave(new \RuntimeException('db'));

        $this->assertMessages([['exception', 'Something went wrong while saving the register.']]);
        $this->assertRedirect('*/*/new');
    }

    private function roleSave(): ?Role
    {
        $repository = $this->createStub(RoleRepositoryInterface::class);
        $new = $this->makeModel(Role::class, 'role_id');
        $factory = $this->createStub(RoleFactory::class);
        $factory->method('create')->willReturn($new);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('POS role with id "8" does not exist.')));
        $repository->method('save')->willReturnCallback(static function ($r) {
            $r->setRoleId(3);
            return $r;
        });
        (new Adminhtml\Role\Save($this->context, $repository, $factory))->execute();

        return $new;
    }

    public function testRoleSaveBuildsClampedPermissionJson(): void
    {
        $this->post = ['name' => ' Manager ', 'max_discount_percent' => '150', 'can_refund' => '1', 'can_edit_layout' => '0'];
        $this->params = ['back' => 1];

        $role = $this->roleSave();

        $permissions = $role->getPermissionsArray();
        $this->assertSame('Manager', $role->getName());
        $this->assertSame(100, $permissions['max_discount_percent']);
        $this->assertTrue($permissions['can_refund']);
        $this->assertFalse($permissions['can_edit_layout']);
        $this->assertFalse($permissions['can_view_reports']);
        $this->assertCount(8, $permissions);
        $this->assertRedirect('*/*/edit', ['id' => 3]);
    }

    public function testRoleSaveValidationAndMissingRole(): void
    {
        $this->post = ['name' => ''];
        $this->roleSave();
        $this->assertMessages([['error', 'Role name is required.']]);
        $this->assertRedirect('*/*/new');

        $this->messages = [];
        $this->post = ['role_id' => '8', 'name' => 'X'];
        $this->roleSave();
        $this->assertMessages([['error', 'POS role with id "8" does not exist.']]);
        $this->assertRedirect('*/*/edit', ['id' => 8]);
    }

    private function userSave(?PosUser $existing = null): PosUser
    {
        $repository = $this->createStub(PosUserRepositoryInterface::class);
        $new = $this->makeModel(PosUser::class, 'user_id');
        $factory = $this->createStub(PosUserFactory::class);
        $factory->method('create')->willReturn($new);
        if ($existing) {
            $repository->method('getById')->willReturn($existing);
        }
        $repository->method('save')->willReturnCallback(static function ($u) {
            if ($u->getUserId() === null) {
                $u->setUserId(11);
            }
            return $u;
        });
        (new Adminhtml\User\Save($this->context, $repository, $factory))->execute();

        return $existing ?? $new;
    }

    public function testUserSaveHashesPasswordAndPin(): void
    {
        $this->post = ['username' => ' ann ', 'name' => 'Ann', 'email' => 'a@b.co', 'role_id' => '', 'password' => 'secret', 'pin' => '1234'];

        $user = $this->userSave();

        $this->assertSame('ann', $user->getUsername());
        $this->assertNull($user->getRoleId());
        $this->assertSame(1, $user->getStatus());
        $this->assertTrue(password_verify('secret', $user->getPasswordHash()));
        $this->assertTrue(password_verify('1234', (string) $user->getPinHash()));
        $this->assertMessages([['success', 'The POS user has been saved.']]);
        $this->assertRedirect('*/*/');
    }

    public static function invalidUserProvider(): array
    {
        return [
            'no username' => [['username' => ' ', 'password' => 'x'], 'Username is required.'],
            'new without password' => [['username' => 'ann'], 'A password is required for a new POS user.'],
            'short pin' => [['username' => 'ann', 'password' => 'x', 'pin' => '12'], 'The PIN must be 4 to 8 digits.'],
            'alpha pin' => [['username' => 'ann', 'password' => 'x', 'pin' => '12ab'], 'The PIN must be 4 to 8 digits.'],
        ];
    }

    #[DataProvider('invalidUserProvider')]
    public function testUserSaveValidation(array $post, string $message): void
    {
        $this->post = $post;

        $this->userSave();

        $this->assertMessages([['error', $message]]);
        $this->assertRedirect('*/*/new');
    }

    public function testExistingUserKeepsPasswordWhenBlank(): void
    {
        $existing = $this->makeModel(PosUser::class, 'user_id', ['user_id' => 11, 'password_hash' => 'old-hash', 'pin_hash' => 'old-pin']);
        $this->post = ['user_id' => '11', 'username' => 'ann', 'role_id' => '2', 'status' => '0', 'password' => '', 'pin' => ''];
        $this->params = ['back' => 1];

        $user = $this->userSave($existing);

        $this->assertSame('old-hash', $user->getPasswordHash());
        $this->assertSame('old-pin', $user->getPinHash());
        $this->assertSame(2, $user->getRoleId());
        $this->assertSame(0, $user->getStatus());
        $this->assertRedirect('*/*/edit', ['id' => 11]);
    }

    private function forceClose(?Session $session, float $expected = 0.0, ?\Throwable $failure = null): array
    {
        $saved = ['session' => [], 'movement' => []];
        $sessionRepository = $this->createStub(SessionRepositoryInterface::class);
        if ($session === null) {
            $sessionRepository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));
        } else {
            $sessionRepository->method('getById')->willReturn($session);
        }
        $sessionRepository->method('save')->willReturnCallback(function ($s) use (&$saved) {
            $saved['session'][] = $s;
            return $s;
        });
        $service = $this->createStub(PosSessionService::class);
        $service->method('expectedCash')->willReturn($expected);
        if ($failure) {
            $service->method('xReport')->willThrowException($failure);
        } else {
            $service->method('xReport')->willReturn(['cash' => ['sales' => 5]]);
        }
        $movementRepository = $this->createStub(CashMovementRepositoryInterface::class);
        $movementRepository->method('save')->willReturnCallback(function ($m) use (&$saved) {
            $saved['movement'][] = $m;
            return $m;
        });
        $movementFactory = $this->createStub(CashMovementInterfaceFactory::class);
        $movementFactory->method('create')->willReturnCallback(fn () => $this->makeModel(CashMovement::class, 'movement_id'));
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-02-02 12:00:00');
        $this->params = ['session_id' => '7'];

        (new Adminhtml\Session\Forceclose($this->context, $sessionRepository, $service, $movementRepository, $movementFactory, $dateTime))->execute();

        return $saved;
    }

    public function testForceCloseUnknownSession(): void
    {
        $this->forceClose(null);

        $this->assertMessages([['error', 'POS session with id "7" does not exist.']]);
        $this->assertRedirect('*/*/index');
    }

    public function testForceCloseAlreadyClosedSession(): void
    {
        $saved = $this->forceClose($this->makeModel(Session::class, 'session_id', ['status' => 'closed']));

        $this->assertMessages([['error', 'POS session #7 is already closed.']]);
        $this->assertSame([], $saved['session']);
    }

    public function testForceCloseSetsCountedToExpectedAndEmptiesDrawer(): void
    {
        $session = $this->makeModel(Session::class, 'session_id', ['session_id' => 7, 'status' => 'open', 'user_id' => 9]);

        $saved = $this->forceClose($session, 42.5);

        $this->assertSame('closed', $session->getStatus());
        $this->assertSame(42.5, $session->getExpectedCash());
        $this->assertSame(42.5, $session->getCountedCash());
        $this->assertSame(0.0, $session->getOverShort());
        $this->assertSame('2026-02-02 12:00:00', $session->getClosedAt());
        $this->assertSame('Force closed from admin', $session->getNote());
        $totals = json_decode((string) $session->getTotalsJson(), true);
        $this->assertSame(['sales' => 5, 'expected' => 42.5, 'counted' => 42.5, 'over_short' => 0], $totals['cash']);
        $this->assertSame('close', $saved['movement'][0]->getType());
        $this->assertSame(-42.5, $saved['movement'][0]->getAmount());
        $this->assertSame(9, $saved['movement'][0]->getUserId());
        $this->assertMessages([['success', 'POS session #7 has been force closed (counted = expected).']]);
    }

    public function testForceCloseWithEmptyDrawerRecordsNoMovement(): void
    {
        $session = $this->makeModel(Session::class, 'session_id', ['session_id' => 7, 'status' => 'open']);

        $saved = $this->forceClose($session, 0.0);

        $this->assertSame([], $saved['movement']);
        $this->assertCount(1, $saved['session']);
    }

    public function testForceCloseFailureIsReported(): void
    {
        $session = $this->makeModel(Session::class, 'session_id', ['session_id' => 7, 'status' => 'open']);

        $saved = $this->forceClose($session, 1.0, new \RuntimeException('db'));

        $this->assertMessages([['error', 'Could not force close POS session #7: db']]);
        $this->assertSame('open', $session->getStatus());
        $this->assertSame([], $saved['session']);
    }
}
