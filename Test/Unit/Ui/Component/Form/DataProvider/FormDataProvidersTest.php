<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Ui\Component\Form\DataProvider;

require_once __DIR__ . '/../../../../autoload.php';

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\Collection as MethodCollection;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\CollectionFactory as MethodCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosUser\Collection as UserCollection;
use Panth\MagePos\Model\ResourceModel\PosUser\CollectionFactory as UserCollectionFactory;
use Panth\MagePos\Model\ResourceModel\QuickKey\Collection as QuickKeyCollection;
use Panth\MagePos\Model\ResourceModel\QuickKey\CollectionFactory as QuickKeyCollectionFactory;
use Panth\MagePos\Model\ResourceModel\Register\Collection as RegisterCollection;
use Panth\MagePos\Model\ResourceModel\Register\CollectionFactory as RegisterCollectionFactory;
use Panth\MagePos\Model\ResourceModel\Role\Collection as RoleCollection;
use Panth\MagePos\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Panth\MagePos\Ui\Component\Form\DataProvider\MethodFormDataProvider;
use Panth\MagePos\Ui\Component\Form\DataProvider\QuickkeyFormDataProvider;
use Panth\MagePos\Ui\Component\Form\DataProvider\RegisterFormDataProvider;
use Panth\MagePos\Ui\Component\Form\DataProvider\RoleFormDataProvider;
use Panth\MagePos\Ui\Component\Form\DataProvider\UserFormDataProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class FormDataProvidersTest extends TestCase
{
    private function item(int $id, array $data): DataObject
    {
        return new DataObject(['id' => $id] + $data);
    }

    private function collection(string $class, array $items): MockObject
    {
        $collection = $this->createMock($class);
        $collection->method('getItems')->willReturn($items);

        return $collection;
    }

    private function factory(string $class, object $collection): object
    {
        $factory = $this->createStub($class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    private function persistor(mixed $kept = null): DataPersistorInterface
    {
        $persistor = $this->createMock(DataPersistorInterface::class);
        $persistor->method('get')->willReturn($kept);

        return $persistor;
    }

    private function request(array $params): RequestInterface
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn ($k) => $params[$k] ?? null);

        return $request;
    }

    public function testMethodProviderLoadsRequestedMethod(): void
    {
        $collection = $this->collection(MethodCollection::class, [$this->item(4, ['code' => 'cash'])]);
        $collection->expects($this->once())->method('addFieldToFilter')->with('method_id', 4);
        $provider = new MethodFormDataProvider(
            'pos_method_form',
            'method_id',
            'id',
            $this->factory(MethodCollectionFactory::class, $collection),
            $this->request(['id' => '4']),
            $this->persistor()
        );

        $data = $provider->getData();

        $this->assertSame([4 => ['id' => 4, 'code' => 'cash']], $data);
        $this->assertSame($data, $provider->getData());
    }

    public function testMethodProviderDefaultsForNewMethod(): void
    {
        $collection = $this->collection(MethodCollection::class, []);
        $collection->expects($this->never())->method('addFieldToFilter');
        $provider = new MethodFormDataProvider(
            'f',
            'method_id',
            'id',
            $this->factory(MethodCollectionFactory::class, $collection),
            $this->request([]),
            $this->persistor()
        );

        $this->assertSame(['' => [
            'type' => 'offline', 'is_active' => '1', 'sort_order' => '0', 'requires_reference' => '0', 'open_drawer' => '0',
        ]], $provider->getData());
    }

    public function testQuickkeyProviderLoadsOrDefaults(): void
    {
        $collection = $this->collection(QuickKeyCollection::class, [$this->item(2, ['label' => 'Tee'])]);
        $collection->expects($this->once())->method('addFieldToFilter')->with('quick_key_id', 2);
        $provider = new QuickkeyFormDataProvider(
            'f',
            'quick_key_id',
            'id',
            $this->factory(QuickKeyCollectionFactory::class, $collection),
            $this->request(['id' => 2]),
            $this->persistor()
        );
        $this->assertSame([2 => ['id' => 2, 'label' => 'Tee']], $provider->getData());

        $empty = new QuickkeyFormDataProvider(
            'f',
            'quick_key_id',
            'id',
            $this->factory(QuickKeyCollectionFactory::class, $this->collection(QuickKeyCollection::class, [])),
            $this->request(['id' => 0]),
            $this->persistor()
        );
        $this->assertSame(['' => ['position' => '0', 'page' => '1']], $empty->getData());
    }

    public function testRegisterProviderLoadsOrDefaults(): void
    {
        $provider = new RegisterFormDataProvider(
            'f',
            'register_id',
            'register_id',
            $this->factory(RegisterCollectionFactory::class, $this->collection(RegisterCollection::class, [$this->item(1, ['name' => 'Front'])])),
            $this->persistor()
        );
        $this->assertSame([1 => ['id' => 1, 'name' => 'Front']], $provider->getData());

        $empty = new RegisterFormDataProvider(
            'f',
            'register_id',
            'register_id',
            $this->factory(RegisterCollectionFactory::class, $this->collection(RegisterCollection::class, [])),
            $this->persistor()
        );
        $this->assertSame(['' => ['status' => '1', 'store_id' => '1']], $empty->getData());
    }

    public function testRoleProviderExplodesPermissionsIntoFormFields(): void
    {
        $provider = new RoleFormDataProvider(
            'f',
            'role_id',
            'id',
            $this->factory(RoleCollectionFactory::class, $this->collection(RoleCollection::class, [
                $this->item(3, ['name' => 'Manager', 'permissions' => '{"max_discount_percent":"25.9","can_refund":true,"can_edit_layout":0}']),
                $this->item(4, ['name' => 'Broken', 'permissions' => 'oops']),
            ])),
            $this->persistor()
        );

        $data = $provider->getData();

        $this->assertArrayNotHasKey('permissions', $data[3]);
        $this->assertSame('25', $data[3]['max_discount_percent']);
        $this->assertSame('1', $data[3]['can_refund']);
        $this->assertSame('0', $data[3]['can_edit_layout']);
        $this->assertSame('0', $data[3]['can_view_reports']);
        $this->assertSame('0', $data[4]['max_discount_percent']);
        $this->assertSame('0', $data[4]['can_refund']);
    }

    public function testRoleProviderDefaultsForNewRole(): void
    {
        $provider = new RoleFormDataProvider(
            'f',
            'role_id',
            'id',
            $this->factory(RoleCollectionFactory::class, $this->collection(RoleCollection::class, [])),
            $this->persistor()
        );

        $defaults = $provider->getData()[''];
        $this->assertSame('0', $defaults['max_discount_percent']);
        $this->assertCount(8, $defaults);
        $this->assertSame(['0'], array_values(array_unique($defaults)));
    }

    public function testUserProviderNeverExposesSecrets(): void
    {
        $provider = new UserFormDataProvider(
            'f',
            'user_id',
            'id',
            $this->factory(UserCollectionFactory::class, $this->collection(UserCollection::class, [
                $this->item(7, ['username' => 'ann', 'password_hash' => 'h1', 'pin_hash' => 'h2']),
            ])),
            $this->persistor()
        );
        $this->assertSame([7 => ['id' => 7, 'username' => 'ann']], $provider->getData());

        $empty = new UserFormDataProvider(
            'f',
            'user_id',
            'id',
            $this->factory(UserCollectionFactory::class, $this->collection(UserCollection::class, [])),
            $this->persistor()
        );
        $this->assertSame(['' => ['status' => '1']], $empty->getData());
    }

    public function testRejectedInputIsMergedOverStoredValuesOnce(): void
    {
        $persistor = $this->persistor(['register_id' => '1', 'name' => 'Typed', 'form_key' => 'k', 'back' => 'edit']);
        $persistor->expects($this->once())->method('clear')->with('panth_pos_register');
        $provider = new RegisterFormDataProvider(
            'f',
            'register_id',
            'register_id',
            $this->factory(RegisterCollectionFactory::class, $this->collection(RegisterCollection::class, [$this->item(1, ['name' => 'Front', 'code' => 'front'])])),
            $persistor
        );

        $this->assertSame([1 => ['id' => 1, 'name' => 'Typed', 'code' => 'front', 'register_id' => '1']], $provider->getData());
    }

    public function testRejectedNewRecordInputReplacesDefaults(): void
    {
        $collection = $this->collection(MethodCollection::class, []);
        $provider = new MethodFormDataProvider(
            'f',
            'method_id',
            'id',
            $this->factory(MethodCollectionFactory::class, $collection),
            $this->request([]),
            $this->persistor(['code' => 'Bad Code', 'type' => 'cash'])
        );

        $data = $provider->getData()[''];
        $this->assertSame('Bad Code', $data['code']);
        $this->assertSame('cash', $data['type']);
        $this->assertSame('1', $data['is_active']);
    }
}
