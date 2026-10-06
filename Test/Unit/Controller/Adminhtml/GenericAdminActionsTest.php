<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Adminhtml;

require_once __DIR__ . '/AbstractAdminControllerTestCase.php';

use Magento\Backend\Model\View\Result\Forward;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Api\PaymentMethodRepositoryInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\QuickKeyRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\RoleRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Controller\Adminhtml;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Model\Session;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;

#[AllowMockObjectsWithoutExpectations]
class GenericAdminActionsTest extends AbstractAdminControllerTestCase
{
    public static function indexProvider(): array
    {
        return [
            [Adminhtml\Method\Index::class, 'Panth_MagePos::methods', 'POS Payment Methods'],
            [Adminhtml\Quickkey\Index::class, 'Panth_MagePos::quickkeys', 'POS Quick Keys'],
            [Adminhtml\Register\Index::class, 'Panth_MagePos::registers', 'POS Registers'],
            [Adminhtml\Report\Index::class, 'Panth_MagePos::reports', 'POS Reports'],
            [Adminhtml\Role\Index::class, 'Panth_MagePos::roles', 'POS Roles'],
            [Adminhtml\Session\Index::class, 'Panth_MagePos::sessions', 'POS Sessions'],
            [Adminhtml\User\Index::class, 'Panth_MagePos::users', 'POS Users'],
        ];
    }

    #[DataProvider('indexProvider')]
    public function testIndexPagesSetMenuTitleAndAcl(string $class, string $resource, string $title): void
    {
        $controller = new $class($this->context, $this->makePageFactory());

        $controller->execute();

        $this->assertSame($resource, $this->activeMenu);
        $this->assertSame([$title], $this->titles);
        $this->assertSame($resource, $class::ADMIN_RESOURCE);
        $this->assertFalse($this->isAllowed($controller));
        $this->allowedResources = [$resource];
        $this->assertTrue($this->isAllowed($controller));
    }

    public static function newActionProvider(): array
    {
        return [
            [Adminhtml\Method\NewAction::class, 'Panth_MagePos::methods'],
            [Adminhtml\Quickkey\NewAction::class, 'Panth_MagePos::quickkeys'],
            [Adminhtml\Register\NewAction::class, 'Panth_MagePos::registers'],
            [Adminhtml\Role\NewAction::class, 'Panth_MagePos::roles'],
            [Adminhtml\User\NewAction::class, 'Panth_MagePos::users'],
        ];
    }

    #[DataProvider('newActionProvider')]
    public function testNewActionsForwardToEdit(string $class, string $resource): void
    {
        $forward = $this->createMock(Forward::class);
        $forward->expects($this->once())->method('forward')->with('edit')->willReturnSelf();
        $factory = $this->createStub(ForwardFactory::class);
        $factory->method('create')->willReturn($forward);

        $this->assertSame($forward, (new $class($this->context, $factory))->execute());
        $this->assertSame($resource, $class::ADMIN_RESOURCE);
    }

    public static function deleteProvider(): array
    {
        return [
            'method' => [Adminhtml\Method\Delete::class, PaymentMethodRepositoryInterface::class, 'id',
                'The POS payment method has been deleted.', 'We can\'t find a POS payment method to delete.', null],
            'quick key' => [Adminhtml\Quickkey\Delete::class, QuickKeyRepositoryInterface::class, 'id',
                'The POS quick key has been deleted.', 'We can\'t find a POS quick key to delete.', null],
            'register' => [Adminhtml\Register\Delete::class, RegisterRepositoryInterface::class, 'register_id',
                'The register has been deleted.', 'We can\'t find a register to delete.', null],
            'role' => [Adminhtml\Role\Delete::class, RoleRepositoryInterface::class, 'id',
                'The POS role has been deleted.', 'We can\'t find a POS role to delete.', 'This POS role no longer exists.'],
            'user' => [Adminhtml\User\Delete::class, PosUserRepositoryInterface::class, 'id',
                'The POS user has been deleted.', 'We can\'t find a POS user to delete.', 'This POS user no longer exists.'],
        ];
    }

    #[DataProvider('deleteProvider')]
    public function testDeleteAcceptsPostOnly(
        string $class,
        string $repoClass,
        string $param,
        string $success,
        string $missing,
        ?string $gone
    ): void
    {
        $interfaces = class_implements($class);

        $this->assertArrayHasKey(\Magento\Framework\App\Action\HttpPostActionInterface::class, $interfaces);
        $this->assertArrayNotHasKey(\Magento\Framework\App\Action\HttpGetActionInterface::class, $interfaces);
    }

    #[DataProvider('deleteProvider')]
    public function testDeleteSucceeds(
        string $class,
        string $repoClass,
        string $param,
        string $success,
        string $missing,
        ?string $gone
    ): void
    {
        $repository = $this->createMock($repoClass);
        $repository->expects($this->once())->method('deleteById')->with(4)->willReturn(true);
        $this->params = [$param => '4'];

        (new $class($this->context, $repository))->execute();

        $this->assertMessages([['success', $success]]);
        $this->assertRedirect('*/*/');
    }

    #[DataProvider('deleteProvider')]
    public function testDeleteWithoutIdReportsError(
        string $class,
        string $repoClass,
        string $param,
        string $success,
        string $missing,
        ?string $gone
    ): void
    {
        $repository = $this->createMock($repoClass);
        $repository->expects($this->never())->method('deleteById');

        (new $class($this->context, $repository))->execute();

        $this->assertMessages([['error', $missing]]);
        $this->assertRedirect('*/*/');
    }

    #[DataProvider('deleteProvider')]
    public function testDeleteOfVanishedEntity(
        string $class,
        string $repoClass,
        string $param,
        string $success,
        string $missing,
        ?string $gone
    ): void
    {
        $repository = $this->createStub($repoClass);
        $repository->method('deleteById')->willThrowException(new NoSuchEntityException(__('Entity 4 is gone.')));
        $this->params = [$param => 4];

        (new $class($this->context, $repository))->execute();

        $this->assertMessages([['error', $gone ?? 'Entity 4 is gone.']]);
        $this->assertRedirect('*/*/');
    }

    public function testRegisterDeleteUnexpectedFailureUsesExceptionMessage(): void
    {
        $repository = $this->createStub(RegisterRepositoryInterface::class);
        $repository->method('deleteById')->willThrowException(new \RuntimeException('fk'));
        $this->params = ['register_id' => 4];

        (new Adminhtml\Register\Delete($this->context, $repository))->execute();

        $this->assertMessages([['exception', 'Something went wrong while deleting the register.']]);
    }

    public static function editProvider(): array
    {
        return [
            'method' => [Adminhtml\Method\Edit::class, PaymentMethodRepositoryInterface::class, 'Panth_MagePos::methods',
                'Edit POS Payment Method #4', 'New POS Payment Method', 'This POS payment method no longer exists.'],
            'quick key' => [Adminhtml\Quickkey\Edit::class, QuickKeyRepositoryInterface::class, 'Panth_MagePos::quickkeys',
                'Edit POS Quick Key #4', 'New POS Quick Key', 'This POS quick key no longer exists.'],
            'role' => [Adminhtml\Role\Edit::class, RoleRepositoryInterface::class, 'Panth_MagePos::roles',
                'Edit POS Role', 'New POS Role', 'This POS role no longer exists.'],
            'user' => [Adminhtml\User\Edit::class, PosUserRepositoryInterface::class, 'Panth_MagePos::users',
                'Edit POS User', 'New POS User', 'This POS user no longer exists.'],
        ];
    }

    #[DataProvider('editProvider')]
    public function testEditExistingEntity(
        string $class,
        string $repoClass,
        string $menu,
        string $editTitle,
        string $newTitle,
        string $gone
    ): void
    {
        $repository = $this->createMock($repoClass);
        $repository->expects($this->once())->method('getById')->with(4);
        $this->params = ['id' => '4'];

        (new $class($this->context, $this->makePageFactory(), $repository))->execute();

        $this->assertSame($menu, $this->activeMenu);
        $this->assertSame([$editTitle], $this->titles);
    }

    #[DataProvider('editProvider')]
    public function testEditNewEntity(
        string $class,
        string $repoClass,
        string $menu,
        string $editTitle,
        string $newTitle,
        string $gone
    ): void
    {
        $repository = $this->createMock($repoClass);
        $repository->expects($this->never())->method('getById');

        (new $class($this->context, $this->makePageFactory(), $repository))->execute();

        $this->assertSame([$newTitle], $this->titles);
    }

    #[DataProvider('editProvider')]
    public function testEditMissingEntityRedirects(
        string $class,
        string $repoClass,
        string $menu,
        string $editTitle,
        string $newTitle,
        string $gone
    ): void
    {
        $repository = $this->createStub($repoClass);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));
        $this->params = ['id' => 4];

        (new $class($this->context, $this->makePageFactory(), $repository))->execute();

        $this->assertMessages([['error', $gone]]);
        $this->assertRedirect('*/*/');
        $this->assertSame([], $this->titles);
    }

    public function testRegisterEditShowsRegisterName(): void
    {
        $repository = $this->createStub(RegisterRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->makeModel(Register::class, 'register_id', ['name' => 'Front']));
        $this->params = ['register_id' => 2];

        (new Adminhtml\Register\Edit($this->context, $this->makePageFactory(), $repository))->execute();

        $this->assertSame(['POS Registers', 'Edit Register "Front"'], $this->titles);
    }

    public function testRegisterEditNewAndMissing(): void
    {
        $repository = $this->createStub(RegisterRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));

        (new Adminhtml\Register\Edit($this->context, $this->makePageFactory(), $repository))->execute();
        $this->assertSame(['POS Registers', 'New Register'], $this->titles);

        $this->params = ['register_id' => 2];
        (new Adminhtml\Register\Edit($this->context, $this->makePageFactory(), $repository))->execute();
        $this->assertMessages([['error', 'This register no longer exists.']]);
        $this->assertRedirect('*/*/');
    }

    public function testSessionViewShowsSessionNumber(): void
    {
        $repository = $this->createStub(SessionRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->makeModel(Session::class, 'session_id', ['session_id' => 7]));
        $this->params = ['session_id' => '7'];

        (new Adminhtml\Session\View($this->context, $this->makePageFactory(), $repository))->execute();

        $this->assertSame('Panth_MagePos::sessions', $this->activeMenu);
        $this->assertSame(['POS Session #7'], $this->titles);
    }

    public function testSessionViewOfUnknownSessionRedirects(): void
    {
        $repository = $this->createStub(SessionRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));
        $this->params = ['session_id' => '7'];

        (new Adminhtml\Session\View($this->context, $this->makePageFactory(), $repository))->execute();

        $this->assertMessages([['error', 'POS session with id "7" does not exist.']]);
        $this->assertRedirect('*/*/index');
    }

    public function testTerminalLaunchRedirectsToStorefrontPos(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);
        $controller = new Adminhtml\Terminal\Launch($this->context, $storeManager);

        $controller->execute();

        $this->assertSame('https://shop.test/pos', $this->redirectUrl);
        $this->assertSame('Panth_MagePos::terminal', Adminhtml\Terminal\Launch::ADMIN_RESOURCE);
    }

    public function testTerminalLaunchFallsBackToCurrentStore(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn(null);
        $storeManager->method('getStore')->willReturn($store);

        (new Adminhtml\Terminal\Launch($this->context, $storeManager))->execute();

        $this->assertSame('https://shop.test/pos', $this->redirectUrl);
    }

    public function testTerminalLaunchFailureGoesToDashboard(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willThrowException(new \Exception('no store'));

        (new Adminhtml\Terminal\Launch($this->context, $storeManager))->execute();

        $this->assertMessages([['error', 'Unable to resolve the store URL for the POS terminal: no store']]);
        $this->assertRedirect('adminhtml/dashboard');
    }
}
