<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Setup\Patch\Data;

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../PosTestHelperTrait.php';

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Panth\MagePos\Model\PaymentMethod;
use Panth\MagePos\Model\PaymentMethodFactory;
use Panth\MagePos\Model\PosUser;
use Panth\MagePos\Model\PosUserFactory;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Model\RegisterFactory;
use Panth\MagePos\Model\ResourceModel\PaymentMethod as PaymentMethodResource;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\Collection as MethodCollection;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\CollectionFactory as MethodCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosUser as PosUserResource;
use Panth\MagePos\Model\ResourceModel\Register as RegisterResource;
use Panth\MagePos\Model\ResourceModel\Role as RoleResource;
use Panth\MagePos\Model\Role;
use Panth\MagePos\Model\RoleFactory;
use Panth\MagePos\Setup\Patch\Data\BackfillReceiptToken;
use Panth\MagePos\Setup\Patch\Data\CreateCustomSaleProduct;
use Panth\MagePos\Setup\Patch\Data\CreateDefaultData;
use Panth\MagePos\Setup\Patch\Data\FixPaymentLinkUrl;
use Panth\MagePos\Setup\Patch\Data\ReplaceEmojiPaymentIcons;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class DataPatchesTest extends TestCase
{
    use PosTestHelperTrait;

    public function testBackfillAssignsUniqueTokensInBatches(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects($this->exactly(2))->method('fetchCol')
            ->willReturnOnConsecutiveCalls(range(1, 500), [501, 502]);
        $tokens = [];
        $connection->expects($this->exactly(502))->method('update')->willReturnCallback(
            function ($table, $bind, $where) use (&$tokens) {
                $this->assertSame('pfx_panth_pos_order', $table);
                $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $bind['receipt_token']);
                $tokens[$where['pos_order_id = ?']] = $bind['receipt_token'];
                return 1;
            }
        );
        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnCallback(static fn ($t) => 'pfx_' . $t);

        $patch = new BackfillReceiptToken($setup);

        $this->assertSame($patch, $patch->apply());
        $this->assertCount(502, array_unique($tokens));
        $this->assertSame([], BackfillReceiptToken::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }

    public function testReplaceEmojiIconsClearsLegacyIcons(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('update')->with(
            'panth_pos_payment_method',
            ['icon' => null],
            ['icon IN (?)' => ["\u{1F4B5}", "\u{1F4B3}", "\u{1F517}"]]
        );
        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        (new ReplaceEmojiPaymentIcons($setup))->apply();

        $this->assertSame([CreateDefaultData::class], ReplaceEmojiPaymentIcons::getDependencies());
    }

    public function testFixPaymentLinkUrlResetsExampleTemplates(): void
    {
        $method = $this->makeModel(PaymentMethod::class, 'method_id', ['payment_url_template' => 'https://example.com/pay']);
        $collection = $this->createMock(MethodCollection::class);
        $collection->expects($this->once())->method('addFieldToFilter')
            ->with('payment_url_template', ['like' => '%example.com%']);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$method]));
        $factory = $this->createStub(MethodCollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $resource = $this->createMock(PaymentMethodResource::class);
        $resource->expects($this->once())->method('save')->with($method);

        (new FixPaymentLinkUrl($factory, $resource, $this->createStub(LoggerInterface::class)))->apply();

        $this->assertSame('', $method->getPaymentUrlTemplate());
    }

    public function testFixPaymentLinkUrlLogsFailures(): void
    {
        $factory = $this->createStub(MethodCollectionFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('db'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('FixPaymentLinkUrl data patch failed: db'));

        (new FixPaymentLinkUrl($factory, $this->createStub(PaymentMethodResource::class), $logger))->apply();
    }

    private function makeAppState(): State
    {
        $state = $this->createMock(State::class);
        $state->expects($this->once())->method('emulateAreaCode')->with('adminhtml')
            ->willReturnCallback(static fn ($area, callable $callback) => $callback());

        return $state;
    }

    public function testCustomSaleProductIsCreatedOnceAsHiddenVirtualProduct(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->with('pos-custom-sale')->willThrowException(new NoSuchEntityException(__('x')));
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDefaultAttributeSetId'])
            ->getMock();
        $product->method('getDefaultAttributeSetId')->willReturn(4);
        $factory = $this->createStub(ProductInterfaceFactory::class);
        $factory->method('create')->willReturn($product);
        $repository->expects($this->once())->method('save')->with($product);
        $websiteA = $this->createStub(Website::class);
        $websiteA->method('getId')->willReturn('1');
        $websiteB = $this->createStub(Website::class);
        $websiteB->method('getId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$websiteA, $websiteB]);

        (new CreateCustomSaleProduct($factory, $repository, $storeManager, $this->makeAppState()))->apply();

        $this->assertSame('pos-custom-sale', $product->getData('sku'));
        $this->assertSame('Custom Sale', $product->getData('name'));
        $this->assertSame('virtual', $product->getData('type_id'));
        $this->assertSame(4, $product->getData('attribute_set_id'));
        $this->assertSame(1, $product->getData('visibility'));
        $this->assertSame([1, 2], $product->getData('website_ids'));
        $this->assertSame(0, $product->getData('stock_data')['manage_stock']);
        $this->assertSame(1, $product->getData('stock_data')['is_in_stock']);
    }

    public function testExistingCustomSaleProductIsLeftAlone(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($this->createStub(Product::class));
        $repository->expects($this->never())->method('save');
        $factory = $this->createMock(ProductInterfaceFactory::class);
        $factory->expects($this->never())->method('create');

        (new CreateCustomSaleProduct($factory, $repository, $this->createStub(StoreManagerInterface::class), $this->makeAppState()))->apply();
    }

    public function testDefaultDataCreatesRolesDisabledAdminRegisterAndMethods(): void
    {
        $saved = ['role' => [], 'user' => [], 'register' => [], 'method' => []];
        $roleResource = $this->createStub(RoleResource::class);
        $roleResource->method('save')->willReturnCallback(function (Role $role) use (&$saved) {
            $role->setId(count($saved['role']) + 1);
            $saved['role'][] = $role;
            return $roleResource ?? null;
        });
        $roleFactory = $this->createStub(RoleFactory::class);
        $roleFactory->method('create')->willReturnCallback(fn () => $this->makeModel(Role::class, 'role_id'));
        $userResource = $this->createStub(PosUserResource::class);
        $userResource->method('save')->willReturnCallback(function ($u) use (&$saved) {
            $saved['user'][] = $u;
            return null;
        });
        $userFactory = $this->createStub(PosUserFactory::class);
        $userFactory->method('create')->willReturnCallback(fn () => $this->makeModel(PosUser::class, 'user_id'));
        $registerResource = $this->createStub(RegisterResource::class);
        $registerResource->method('save')->willReturnCallback(function ($r) use (&$saved) {
            $saved['register'][] = $r;
            return null;
        });
        $registerFactory = $this->createStub(RegisterFactory::class);
        $registerFactory->method('create')->willReturnCallback(fn () => $this->makeModel(Register::class, 'register_id'));
        $methodResource = $this->createStub(PaymentMethodResource::class);
        $methodResource->method('save')->willReturnCallback(function ($m) use (&$saved) {
            $saved['method'][] = $m;
            return null;
        });
        $methodFactory = $this->createStub(PaymentMethodFactory::class);
        $methodFactory->method('create')->willReturnCallback(fn () => $this->makeModel(PaymentMethod::class, 'method_id'));
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        $patch = new CreateDefaultData(
            $roleFactory,
            $roleResource,
            $userFactory,
            $userResource,
            $registerFactory,
            $registerResource,
            $methodFactory,
            $methodResource,
            $storeManager
        );
        $patch->apply();

        $this->assertSame(['Administrator', 'Cashier'], array_map(static fn ($r) => $r->getName(), $saved['role']));
        $this->assertTrue($saved['role'][0]->getPermissionsArray()['can_refund']);
        $this->assertSame(10, $saved['role'][1]->getPermissionsArray()['max_discount_percent']);
        $admin = $saved['user'][0];
        $this->assertSame('admin', $admin->getUsername());
        $this->assertSame(0, $admin->getStatus());
        $this->assertSame(1, $admin->getRoleId());
        $this->assertStringStartsWith('$', $admin->getPasswordHash());
        $this->assertSame('main', $saved['register'][0]->getCode());
        $this->assertSame(3, $saved['register'][0]->getStoreId());
        $this->assertSame(['cash', 'card', 'payment_link'], array_map(static fn ($m) => $m->getCode(), $saved['method']));
        $this->assertSame(1, $saved['method'][0]->getOpenDrawer());
        $this->assertSame(1, $saved['method'][1]->getRequiresReference());
        $this->assertSame('online', $saved['method'][2]->getType());
    }

    public function testDefaultDataSkipsExistingRecords(): void
    {
        $roleResource = $this->createMock(RoleResource::class);
        $roleResource->method('load')->willReturnCallback(static function ($role) {
            $role->setId(9);
        });
        $roleResource->expects($this->never())->method('save');
        $roleFactory = $this->createStub(RoleFactory::class);
        $roleFactory->method('create')->willReturnCallback(fn () => $this->makeModel(Role::class, 'role_id'));
        $loadExisting = static function ($model) {
            $model->setId(1);
        };
        $userResource = $this->createMock(PosUserResource::class);
        $userResource->method('load')->willReturnCallback($loadExisting);
        $userResource->expects($this->never())->method('save');
        $registerResource = $this->createMock(RegisterResource::class);
        $registerResource->method('load')->willReturnCallback($loadExisting);
        $registerResource->expects($this->never())->method('save');
        $methodResource = $this->createMock(PaymentMethodResource::class);
        $methodResource->method('load')->willReturnCallback($loadExisting);
        $methodResource->expects($this->never())->method('save');
        $userFactory = $this->createStub(PosUserFactory::class);
        $userFactory->method('create')->willReturnCallback(fn () => $this->makeModel(PosUser::class, 'user_id'));
        $registerFactory = $this->createStub(RegisterFactory::class);
        $registerFactory->method('create')->willReturnCallback(fn () => $this->makeModel(Register::class, 'register_id'));
        $methodFactory = $this->createStub(PaymentMethodFactory::class);
        $methodFactory->method('create')->willReturnCallback(fn () => $this->makeModel(PaymentMethod::class, 'method_id'));

        (new CreateDefaultData(
            $roleFactory,
            $roleResource,
            $userFactory,
            $userResource,
            $registerFactory,
            $registerResource,
            $methodFactory,
            $methodResource,
            $this->createStub(StoreManagerInterface::class)
        ))->apply();
    }
}
