<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../PosTestHelperTrait.php';

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterface;
use Magento\InventoryApi\Api\Data\StockSourceLinkSearchResultsInterface;
use Magento\InventoryApi\Api\GetStockSourceLinksInterface;
use Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Panth\MagePos\Api\Data\QuickKeyInterfaceFactory;
use Panth\MagePos\Api\QuickKeyRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Model\QuickKey;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Model\ResourceModel\QuickKey\Collection as QuickKeyCollection;
use Panth\MagePos\Model\ResourceModel\QuickKey\CollectionFactory as QuickKeyCollectionFactory;
use Panth\MagePos\Service\CatalogService;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CatalogServiceTest extends TestCase
{
    use PosTestHelperTrait;

    private const CHAIN = [
        'setStore', 'addStoreFilter', 'addAttributeToSelect', 'addPriceData', 'addAttributeToSort',
        'setPageSize', 'setCurPage', 'setOrder', 'addCategoriesFilter', 'load', 'addMediaGalleryData',
    ];

    private array $collections = [];
    private array $filters = [];
    private array $fetchAllByTable = [];
    private array $fetchColQueue = [];
    private string $barcodeAttribute = 'sku';
    private bool $showOutOfStock = true;
    private array $existingAttributes = [];
    private array $quickKeys = [];
    private ImageHelper&MockObject $imageHelper;
    private StockHelper&MockObject $stockHelper;
    private CategoryRepositoryInterface&MockObject $categoryRepository;
    private CategoryCollectionFactory&MockObject $categoryCollectionFactory;
    private QuickKeyRepositoryInterface&MockObject $quickKeyRepository;
    private QuickKeyInterfaceFactory&MockObject $quickKeyFactory;
    private Store&MockObject $store;

    protected function setUp(): void
    {
        $this->collections = [];
        $this->filters = [];
        $this->fetchAllByTable = [];
        $this->fetchColQueue = [];
        $this->quickKeys = [];
        $this->imageHelper = $this->createMock(ImageHelper::class);
        $this->imageHelper->method('init')->willReturnSelf();
        $this->imageHelper->method('resize')->willReturnSelf();
        $this->imageHelper->method('getUrl')->willReturn('http://img/p.jpg');
        $this->stockHelper = $this->createMock(StockHelper::class);
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->categoryCollectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $this->quickKeyRepository = $this->createMock(QuickKeyRepositoryInterface::class);
        $this->quickKeyFactory = $this->createMock(QuickKeyInterfaceFactory::class);
        $this->store = $this->createMock(Store::class);
        $this->store->method('getId')->willReturn(1);
        $this->store->method('getWebsiteId')->willReturn(1);
    }

    private function queueCollection(array $products = [], ?int $size = null, array $allIds = []): ProductCollection
    {
        $collection = $this->createStub(ProductCollection::class);
        $index = count($this->collections);
        foreach (self::CHAIN as $method) {
            $collection->method($method)->willReturnSelf();
        }
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function ($attribute, $condition = null) use ($collection, $index) {
                $this->filters[$index][] = [$attribute, $condition];
                return $collection;
            }
        );
        $collection->method('getSize')->willReturn($size ?? count($products));
        $collection->method('getIterator')->willReturnCallback(static fn () => new \ArrayIterator($products));
        $collection->method('getFirstItem')->willReturn($products[0] ?? $this->makeProduct([]));
        $collection->method('getAllIds')->willReturn($allIds);
        $select = $this->createStub(Select::class);
        $select->method('distinct')->willReturnSelf();
        $collection->method('getSelect')->willReturn($select);
        $this->collections[] = $collection;

        return $collection;
    }

    private function makeProduct(array $data, ?object $extension = null, array $attributes = [], ?array $gallery = null): Product&MockObject
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getSku', 'getExtensionAttributes', 'getFinalPrice', 'getPrice', 'getAttributes', 'getMediaGalleryImages', 'getTypeInstance', 'getOptions'])
            ->getMock();
        $product->method('getAttributes')->willReturn($attributes);
        $product->method('getMediaGalleryImages')->willReturn($gallery);
        $product->method('getTypeInstance')->willReturn($data['__type'] ?? null);
        $product->method('getOptions')->willReturn($data['__options'] ?? null);
        unset($data['__type'], $data['__options']);
        $product->setData($data);
        $product->method('getId')->willReturn($data['entity_id'] ?? null);
        $product->method('getSku')->willReturn($data['sku'] ?? null);
        $product->method('getExtensionAttributes')->willReturn($extension);
        $product->method('getFinalPrice')->willReturn($data['computed_final'] ?? 0.0);
        $product->method('getPrice')->willReturn($data['computed_price'] ?? null);

        return $product;
    }

    private function makeService(array $msi = []): CatalogService
    {
        $productFactory = $this->createStub(ProductCollectionFactory::class);
        $productFactory->method('create')->willReturnCallback(function () {
            return array_shift($this->collections) ?? $this->queueCollectionAndTake();
        });
        $quickKeyFactory = $this->createStub(QuickKeyCollectionFactory::class);
        $quickKeyFactory->method('create')->willReturnCallback(fn () => $this->makeQuickKeyCollection());
        $eav = $this->createStub(EavConfig::class);
        $eav->method('getAttribute')->willReturnCallback(function ($entity, $code) {
            $attribute = $this->createStub(AbstractAttribute::class);
            $attribute->method('getId')->willReturn(in_array($code, $this->existingAttributes, true) ? 5 : null);
            return $attribute;
        });
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->store);
        $website = $this->createStub(Website::class);
        $website->method('getStoreIds')->willReturn([1, 2]);
        $storeManager->method('getWebsite')->willReturn($website);
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        $connection->method('fetchAll')->willReturnCallback(fn (Select $s) => $this->fetchAllByTable[$s->table ?? ''] ?? []);
        $connection->method('fetchCol')->willReturnCallback(fn () => array_shift($this->fetchColQueue) ?? []);
        $connection->method('prepareSqlCondition')->willReturnCallback(
            static fn ($field, $cond) => 'FIND_IN_SET(' . $cond['finset'] . ', ' . $field . ')'
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturn(true);
        $config = $this->createStub(Config::class);
        $config->method('getSearchPageSize')->willReturn(2);
        $config->method('getBarcodeAttribute')->willReturnCallback(fn () => $this->barcodeAttribute);
        $config->method('isShowOutOfStock')->willReturnCallback(fn () => $this->showOutOfStock);
        $config->method('getCustomProductSku')->willReturn('pos-custom-sale');
        $config->method('getCatalogCacheLimit')->willReturn(100);

        return new CatalogService(
            $productFactory,
            $this->categoryCollectionFactory,
            $this->categoryRepository,
            $quickKeyFactory,
            $this->stockHelper,
            $this->imageHelper,
            $eav,
            $storeManager,
            $resource,
            $moduleManager,
            $config,
            $msi['registerRepository'] ?? null,
            $msi['salableQty'] ?? null,
            null,
            $msi['stockSourceLinks'] ?? null,
            $msi['defaultStock'] ?? null,
            $msi['criteriaBuilder'] ?? null,
            null,
            $this->quickKeyRepository,
            $this->quickKeyFactory
        );
    }

    private function queueCollectionAndTake(): ProductCollection
    {
        $this->queueCollection();
        return array_shift($this->collections);
    }

    private function makeSelect(): Select
    {
        $select = new class extends Select {
            public ?string $table = null;

            public function __construct()
            {
            }

            public function from($name, $cols = '*', $schema = null)
            {
                $this->table = is_array($name) ? (string) reset($name) : (string) $name;
                return $this;
            }

            public function join($name, $cond, $cols = '*', $schema = null)
            {
                return $this;
            }

            public function columns($cols = '*', $correlationName = null)
            {
                return $this;
            }

            public function distinct($flag = true)
            {
                return $this;
            }

            public function where($cond, $value = null, $type = null)
            {
                return $this;
            }

            public function limit($count = null, $offset = null)
            {
                return $this;
            }

            public function group($spec)
            {
                return $this;
            }

            public function order($spec)
            {
                return $this;
            }
        };

        return $select;
    }

    private function makeQuickKeyCollection(): QuickKeyCollection
    {
        $collection = $this->createStub(QuickKeyCollection::class);
        $collection->method('addRegisterFilter')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $value) use ($collection) {
            $this->filters['quick_key'][] = [$field, $value];
            return $collection;
        });
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('getIterator')->willReturnCallback(fn () => new \ArrayIterator($this->quickKeys));
        $collection->method('getFirstItem')->willReturnCallback(
            fn () => $this->filters['quick_key_first'] ?? $this->makeModel(QuickKey::class, 'quick_key_id')
        );

        return $collection;
    }

    private function makeQuickKey(array $data): QuickKey
    {
        return $this->makeModel(QuickKey::class, 'quick_key_id', $data);
    }

    public function testEmptySearchReturnsEmptyEnvelope(): void
    {
        $this->assertSame(
            ['items' => [], 'total_count' => 0, 'page' => 1, 'page_size' => 2],
            $this->makeService()->search('   ', 1)
        );
    }

    public function testSearchSplitsTermsEscapesLikeAndAddsBarcodeCondition(): void
    {
        $this->barcodeAttribute = 'ean';
        $this->existingAttributes = ['ean'];
        $this->showOutOfStock = false;
        $this->stockHelper->expects($this->once())->method('addInStockFilterToCollection');
        $this->queueCollection([$this->makeProduct(['entity_id' => 1, 'sku' => 'A', 'name' => 'Red 50%', 'final_price' => 5, 'price' => 5])], 1);

        $result = $this->makeService()->search(' red  50% red ', 1);

        $this->assertSame(1, $result['total_count']);
        $termFilters = array_values(array_filter($this->filters[0], static fn ($f) => is_array($f[0])));
        $this->assertCount(2, $termFilters);
        $this->assertSame(['attribute' => 'name', 'like' => '%red%'], $termFilters[0][0][0]);
        $this->assertSame(['attribute' => 'sku', 'like' => '%50\\%%'], $termFilters[1][0][1]);
        $this->assertSame(['attribute' => 'ean', 'eq' => 'red  50% red'], end($termFilters[0][0]));
        $this->assertContains(['sku', ['neq' => 'pos-custom-sale']], $this->filters[0]);
    }

    public function testSearchAddsProductsMatchedByAttributeOptionsIncludingParents(): void
    {
        $this->fetchAllByTable['eav_attribute_option_value'] = [
            ['attribute_id' => '90', 'option_id' => '11', 'backend_type' => 'int', 'input' => 'select'],
            ['attribute_id' => '91', 'option_id' => '12', 'backend_type' => 'varchar', 'input' => 'multiselect'],
            ['attribute_id' => '92', 'option_id' => '13', 'backend_type' => 'static', 'input' => 'select'],
        ];
        $this->fetchColQueue = [['5', '6'], ['6', '7'], ['50']];
        $this->queueCollection([], 0);

        $this->makeService()->search('blue', 1);

        $termFilters = array_values(array_filter($this->filters[0], static fn ($f) => is_array($f[0])));
        $optionFilter = end($termFilters[0][0]);
        $this->assertSame(['attribute' => 'entity_id', 'in' => [5, 6, 7, 50]], $optionFilter);
    }

    public function testSearchPageBeyondResultsIsEmpty(): void
    {
        $this->queueCollection([$this->makeProduct(['entity_id' => 1])], 3);

        $result = $this->makeService()->search('x', 1, 3);

        $this->assertSame([], $result['items']);
        $this->assertSame(3, $result['total_count']);
        $this->assertSame(3, $result['page']);
    }

    public function testProductRowsReflectPricesStockAndImages(): void
    {
        $stockItem = new DataObject(['is_in_stock' => true, 'qty' => 7, 'min_qty' => 3]);
        $extension = new class ($stockItem) {
            public function __construct(private readonly DataObject $item)
            {
            }

            public function getStockItem(): DataObject
            {
                return $this->item;
            }
        };
        $simple = $this->makeProduct([
            'entity_id' => 1, 'sku' => 'TEE', 'name' => 'Tee', 'type_id' => 'simple',
            'final_price' => '8', 'price' => '10', 'has_options' => '1',
        ], $extension);
        $configurable = $this->makeProduct([
            'entity_id' => 2, 'sku' => 'CFG', 'name' => 'Hoodie', 'type_id' => 'configurable',
            'final_price' => '0', 'min_price' => '25', 'price' => '25', 'thumbnail' => 'x.jpg',
        ]);
        $this->queueCollection([$simple, $configurable], 2);
        $this->imageHelper = $this->createMock(ImageHelper::class);
        $this->imageHelper->method('init')->willReturnCallback(function ($product) {
            if ($product->getId() === 2) {
                throw new \Exception('no image');
            }
            return $this->imageHelper;
        });
        $this->imageHelper->method('resize')->willReturnSelf();
        $this->imageHelper->method('getUrl')->willReturn('http://img/tee.jpg');
        $this->imageHelper->expects($this->once())->method('getDefaultPlaceholderUrl')->with('thumbnail')
            ->willReturn('http://img/placeholder.jpg');

        $items = $this->makeService()->search('t', 1)['items'];

        $this->assertSame([
            'id' => 1, 'sku' => 'TEE', 'name' => 'Tee', 'price' => 8.0, 'image' => 'http://img/tee.jpg',
            'type' => 'simple', 'price_from' => false, 'has_options' => true, 'requires_options' => false,
            'original_price' => 10.0, 'in_stock' => true, 'salable_qty' => 4.0, 'low_stock' => true,
        ], $items[0]);
        $this->assertSame(25.0, $items[1]['price']);
        $this->assertTrue($items[1]['price_from']);
        $this->assertTrue($items[1]['requires_options']);
        $this->assertNull($items[1]['original_price']);
        $this->assertNull($items[1]['salable_qty']);
        $this->assertTrue($items[1]['in_stock']);
        $this->assertSame('http://img/placeholder.jpg', $items[1]['image']);
    }

    public function testProductNamesAreEntityDecoded(): void
    {
        $this->queueCollection([$this->makeProduct([
            'entity_id' => 5, 'sku' => 'BAND', 'name' => 'Pursuit Lumaflex&trade; Tone Band',
            'type_id' => 'simple', 'final_price' => '5',
        ])], 1);

        $row = $this->makeService()->search('band', 1)['items'][0];

        $this->assertSame("Pursuit Lumaflex\u{2122} Tone Band", $row['name']);
    }

    public function testOutOfStockLegacyItemReportsZero(): void
    {
        $extension = new class {
            public function getStockItem(): DataObject
            {
                return new DataObject(['is_in_stock' => false, 'qty' => 5]);
            }
        };
        $this->queueCollection([$this->makeProduct(['entity_id' => 1, 'type_id' => 'simple', 'final_price' => 1], $extension)], 1);

        $row = $this->makeService()->search('x', 1)['items'][0];

        $this->assertFalse($row['in_stock']);
        $this->assertSame(0.0, $row['salable_qty']);
        $this->assertFalse($row['low_stock']);
    }

    public function testMsiSalableQtyUsesRegisterSourceStockWithBestPriority(): void
    {
        $registerRepository = $this->createStub(RegisterRepositoryInterface::class);
        $registerRepository->method('getById')->willReturn(
            $this->makeModel(Register::class, 'register_id', ['source_code' => 'shop1'])
        );
        $linkA = $this->createStub(StockSourceLinkInterface::class);
        $linkA->method('getStockId')->willReturn(3);
        $linkA->method('getPriority')->willReturn(5);
        $linkB = $this->createStub(StockSourceLinkInterface::class);
        $linkB->method('getStockId')->willReturn(4);
        $linkB->method('getPriority')->willReturn(1);
        $linkResults = $this->createStub(StockSourceLinkSearchResultsInterface::class);
        $linkResults->method('getItems')->willReturn([$linkA, $linkB]);
        $stockLinks = $this->createMock(GetStockSourceLinksInterface::class);
        $stockLinks->expects($this->once())->method('execute')->willReturn($linkResults);
        $salable = $this->createMock(GetProductSalableQtyInterface::class);
        $salable->expects($this->once())->method('execute')->with('TEE', 4)->willReturn(12.0);
        $this->queueCollection([
            $this->makeProduct(['entity_id' => 1, 'sku' => 'TEE', 'type_id' => 'simple', 'final_price' => 1]),
            $this->makeProduct(['entity_id' => 1, 'sku' => 'TEE', 'type_id' => 'simple', 'final_price' => 1]),
        ], 2);

        $items = $this->makeService([
            'registerRepository' => $registerRepository,
            'salableQty' => $salable,
            'stockSourceLinks' => $stockLinks,
            'defaultStock' => $this->createStub(DefaultStockProviderInterface::class),
            'criteriaBuilder' => $this->makeCriteriaBuilder(),
        ])->search('tee', 1, 1, 0, 7)['items'];

        $this->assertSame(12.0, $items[0]['salable_qty']);
        $this->assertSame(12.0, $items[1]['salable_qty']);
        $this->assertFalse($items[0]['low_stock']);
        $this->assertContains(['source_code', 'shop1', 'eq'], $this->criteriaFilters);
    }

    public function testMsiFallsBackToDefaultStockWithoutRegister(): void
    {
        $default = $this->createStub(DefaultStockProviderInterface::class);
        $default->method('getId')->willReturn(1);
        $salable = $this->createMock(GetProductSalableQtyInterface::class);
        $salable->expects($this->once())->method('execute')->with('TEE', 1)->willThrowException(new \Exception('no'));
        $this->queueCollection([$this->makeProduct(['entity_id' => 1, 'sku' => 'TEE', 'type_id' => 'simple', 'final_price' => 1])], 1);

        $row = $this->makeService(['salableQty' => $salable, 'defaultStock' => $default])->search('tee', 1)['items'][0];

        $this->assertSame(0.0, $row['salable_qty']);
        $this->assertFalse($row['in_stock']);
    }

    public function testByBarcodeHandlesBlankUnknownAttributeAndMissingProduct(): void
    {
        $service = $this->makeService();
        $this->assertNull($service->byBarcode('  ', 1));

        $this->barcodeAttribute = 'ean';
        $this->queueCollection([]);
        $this->assertNull($service->byBarcode('123', 1));
        $this->assertContains(['sku', '123'], $this->filters[0]);
    }

    public function testByBarcodeFindsProductByConfiguredAttribute(): void
    {
        $this->barcodeAttribute = 'ean';
        $this->existingAttributes = ['ean'];
        $this->queueCollection([$this->makeProduct(['entity_id' => 9, 'sku' => 'S9', 'type_id' => 'virtual', 'final_price' => 3])]);

        $row = $this->makeService()->byBarcode(' 4006 ', 1);

        $this->assertSame(9, $row['id']);
        $this->assertContains(['ean', '4006'], $this->filters[0]);
    }

    public function testCategoryTreeNestsChildrenUnderRootChildren(): void
    {
        $this->store->method('getRootCategoryId')->willReturn(2);
        $root = $this->createStub(Category::class);
        $root->method('getPath')->willReturn('1/2');
        $root->method('getLevel')->willReturn(1);
        $this->categoryRepository->method('get')->with(2, 1)->willReturn($root);
        $collection = $this->createStub(CategoryCollection::class);
        foreach (['setStoreId', 'addAttributeToSelect', 'addIsActiveFilter', 'addFieldToFilter', 'setOrder'] as $m) {
            $collection->method($m)->willReturnSelf();
        }
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            new DataObject(['id' => 3, 'name' => 'Men', 'parent_id' => 2]),
            new DataObject(['id' => 4, 'name' => 'Women', 'parent_id' => 2]),
            new DataObject(['id' => 5, 'name' => 'Shirts', 'parent_id' => 3]),
            new DataObject(['id' => 6, 'name' => 'Orphan', 'parent_id' => 99]),
        ]));
        $this->categoryCollectionFactory->method('create')->willReturn($collection);

        $tree = $this->makeService()->categoryTree(1);

        $this->assertSame([3, 4], array_column($tree, 'id'));
        $this->assertSame([['id' => 5, 'name' => 'Shirts', 'parent_id' => 3, 'children' => []]], $tree[0]['children']);
        $this->assertSame([], $tree[1]['children']);
    }

    public function testCategoryTreeWithoutRootIsEmpty(): void
    {
        $this->store->method('getRootCategoryId')->willReturn(0);
        $this->categoryRepository->expects($this->never())->method('get');

        $this->assertSame([], $this->makeService()->categoryTree(1));
    }

    public function testCategoryTreeWithMissingRootIsEmpty(): void
    {
        $this->store->method('getRootCategoryId')->willReturn(2);
        $this->categoryRepository->method('get')->willThrowException(new NoSuchEntityException(__('x')));

        $this->assertSame([], $this->makeService()->categoryTree(1));
    }

    public function testBestSellersKeepRankOrderAndSkipUnsellable(): void
    {
        $this->fetchAllByTable['sales_order_item'] = [
            ['product_id' => '30'], ['product_id' => '10'], ['product_id' => '0'], ['product_id' => '20'],
        ];
        $this->queueCollection([], 0, ['10', '30']);
        $this->queueCollection([
            $this->makeProduct(['entity_id' => 10, 'sku' => 'B', 'type_id' => 'simple', 'final_price' => 1]),
            $this->makeProduct(['entity_id' => 30, 'sku' => 'A', 'type_id' => 'simple', 'final_price' => 1]),
        ]);

        $result = $this->makeService()->bestSellers(1, 5);

        $this->assertSame(['A', 'B'], array_column($result['items'], 'sku'));
        $this->assertSame(2, $result['total_count']);
        $this->assertSame(5, $result['page_size']);
    }

    public function testBestSellersPageBeyondRankedIdsIsEmpty(): void
    {
        $this->fetchAllByTable['sales_order_item'] = [['product_id' => '10']];
        $this->queueCollection([], 0, ['10']);

        $result = $this->makeService()->bestSellers(1, 500, 0, null, 3);

        $this->assertSame(['items' => [], 'total_count' => 1, 'page' => 3, 'page_size' => 100], $result);
    }

    public function testBestSellersFallBackToNewestProducts(): void
    {
        $this->queueCollection([$this->makeProduct(['entity_id' => 1, 'sku' => 'NEW', 'type_id' => 'simple', 'final_price' => 1])], 1);

        $result = $this->makeService()->bestSellers(1, 0);

        $this->assertSame(['NEW'], array_column($result['items'], 'sku'));
        $this->assertSame(1, $result['page_size']);
    }

    public function testQuickKeysMergeProductRowsWithTileSettings(): void
    {
        $this->quickKeys = [
            $this->makeQuickKey(['quick_key_id' => 1, 'product_id' => 10, 'label' => '', 'color' => '#f00', 'position' => 2, 'page' => 1]),
            $this->makeQuickKey(['quick_key_id' => 2, 'product_id' => 11, 'label' => 'Gone', 'position' => 3, 'page' => 1]),
            $this->makeQuickKey(['quick_key_id' => 3, 'product_id' => 10, 'label' => 'Tee again', 'position' => 4, 'page' => 2]),
        ];
        $this->queueCollection([$this->makeProduct(['entity_id' => 10, 'sku' => 'TEE', 'name' => 'Tee', 'type_id' => 'simple', 'final_price' => 2])]);

        $tiles = $this->makeService()->quickKeys(4);

        $this->assertCount(2, $tiles);
        $this->assertSame('Tee', $tiles[0]['label']);
        $this->assertSame('#f00', $tiles[0]['color']);
        $this->assertSame('TEE', $tiles[0]['sku']);
        $this->assertSame('Tee again', $tiles[1]['label']);
        $this->assertSame(2, $tiles[1]['page']);
        $this->assertContains(['entity_id', ['in' => [10, 11]]], $this->filters[0]);
    }

    public function testQuickKeysWithoutKeysSkipProductLookup(): void
    {
        $this->assertSame([], $this->makeService()->quickKeys(null));
        $this->assertSame([], $this->collections);
    }

    public function testQuickKeySaveUpdatesExistingKeyWithSanitisedInput(): void
    {
        $key = $this->makeQuickKey(['quick_key_id' => 5, 'register_id' => 4, 'product_id' => 10, 'label' => 'Old', 'color' => '#000']);
        $this->quickKeyRepository->method('getById')->with(5)->willReturn($key);
        $this->quickKeyRepository->expects($this->once())->method('save')->with($key)->willReturnArgument(0);

        $row = $this->makeService()->quickKeySave([
            'quick_key_id' => '5', 'label' => '  ' . str_repeat('x', 70), 'color' => 'red', 'page' => '0', 'position' => '-3',
        ], 4);

        $this->assertSame(str_repeat('x', 64), $row['label']);
        $this->assertSame('#000', $row['color']);
        $this->assertSame(1, $row['page']);
        $this->assertSame(0, $row['position']);
    }

    public function testQuickKeySaveRejectsKeyOfAnotherRegister(): void
    {
        $this->quickKeyRepository->method('getById')->willReturn($this->makeQuickKey(['quick_key_id' => 5, 'register_id' => 9]));
        $this->quickKeyRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This quick key belongs to another register.');
        $this->makeService()->quickKeySave(['quick_key_id' => 5], 4);
    }

    public function testQuickKeySaveOfUnknownKeyFails(): void
    {
        $this->quickKeyRepository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Quick key not found.');
        $this->makeService()->quickKeySave(['quick_key_id' => 5], 4);
    }

    public function testQuickKeySaveRequiresProduct(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('A product is required to pin a quick key.');
        $this->makeService()->quickKeySave(['product_id' => 'abc'], 4);
    }

    public function testQuickKeySaveReturnsExistingPinForSameProduct(): void
    {
        $this->filters['quick_key_first'] = $this->makeQuickKey(['quick_key_id' => 8, 'product_id' => 10, 'register_id' => 4]);
        $this->quickKeyRepository->expects($this->never())->method('save');

        $row = $this->makeService()->quickKeySave(['product_id' => 10], 4);

        $this->assertSame(8, $row['quick_key_id']);
        $this->assertContains(['product_id', 10], $this->filters['quick_key']);
    }

    public function testQuickKeySaveRejectsUnsellableProduct(): void
    {
        $this->queueCollection([]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This product cannot be pinned - it is not sellable on this store.');
        $this->makeService()->quickKeySave(['product_id' => 10], 4);
    }

    public function testQuickKeySaveAppendsNewKeyAfterLastPositionOfLastPage(): void
    {
        $this->quickKeys = [
            $this->makeQuickKey(['page' => 1, 'position' => 7]),
            $this->makeQuickKey(['page' => 2, 'position' => 1]),
            $this->makeQuickKey(['page' => 2, 'position' => 4]),
        ];
        $this->queueCollection([$this->makeProduct(['entity_id' => 10])]);
        $new = $this->makeQuickKey([]);
        $this->quickKeyFactory->method('create')->willReturn($new);
        $this->quickKeyRepository->expects($this->once())->method('save')->with($new)->willReturnArgument(0);

        $row = $this->makeService()->quickKeySave(['product_id' => 10, 'color' => '#ABCDEF', 'label' => ' '], 4);

        $this->assertSame(['quick_key_id' => 0, 'register_id' => 4, 'product_id' => 10, 'label' => null, 'color' => '#ABCDEF', 'page' => 2, 'position' => 5], $row);
    }

    public function testQuickKeySaveOnExplicitEmptyPageStartsAtZero(): void
    {
        $this->quickKeys = [$this->makeQuickKey(['page' => 1, 'position' => 7])];
        $this->queueCollection([$this->makeProduct(['entity_id' => 10])]);
        $this->quickKeyFactory->method('create')->willReturn($this->makeQuickKey([]));
        $this->quickKeyRepository->method('save')->willReturnArgument(0);

        $row = $this->makeService()->quickKeySave(['product_id' => 10, 'page' => 3], null);

        $this->assertSame(3, $row['page']);
        $this->assertSame(0, $row['position']);
        $this->assertNull($row['register_id']);
    }

    public function testQuickKeyRemoveValidatesAndDeletes(): void
    {
        $key = $this->makeQuickKey(['quick_key_id' => 5, 'register_id' => null]);
        $this->quickKeyRepository->method('getById')->willReturn($key);
        $this->quickKeyRepository->expects($this->once())->method('delete')->with($key);

        $this->makeService()->quickKeyRemove(5, 4);
    }

    public function testQuickKeyRemoveRejectsInvalidId(): void
    {
        $this->quickKeyRepository->expects($this->never())->method('getById');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Quick key not found.');
        $this->makeService()->quickKeyRemove(0, 4);
    }

    public function testQuickKeyRemoveRejectsForeignKey(): void
    {
        $this->quickKeyRepository->method('getById')->willReturn($this->makeQuickKey(['quick_key_id' => 5, 'register_id' => 2]));
        $this->quickKeyRepository->expects($this->never())->method('delete');

        $this->expectException(LocalizedException::class);
        $this->makeService()->quickKeyRemove(5, 4);
    }

    public function testProductDetailSanitisesDescriptions(): void
    {
        $this->queueCollection([$this->makeProduct([
            'entity_id' => 3, 'sku' => 'D', 'type_id' => 'simple', 'final_price' => 1,
            'short_description' => '<p onclick="x()">Same</p>',
            'description' => '<p>Hi {{widget type="x"}}<a href="javascript:alert(1)">bad</a>'
                . '<a href="https://ok.test">ok</a><img src="data:image/png;base64,AA">'
                . '<span style="color:red">red</span><script>evil()</script></p>',
        ])]);

        $row = $this->makeService()->productDetail(3, 1);

        $this->assertSame(
            '<p>Hi <a >bad</a><a href="https://ok.test">ok</a><img src="data:image/png;base64,AA"><span>red</span>evil()</p>',
            $row['description']
        );
        $this->assertSame('<p >Same</p>', $row['short_description']);
        $this->assertSame(['http://img/p.jpg'], $row['gallery']);
        $this->assertSame([], $row['attributes']);
    }

    private function makeAttribute(string $code, string $label, bool $visible, bool $userDefined, string $input, mixed $frontValue): AbstractAttribute
    {
        $frontend = $this->createStub(\Magento\Eav\Model\Entity\Attribute\Frontend\AbstractFrontend::class);
        if ($frontValue instanceof \Throwable) {
            $frontend->method('getValue')->willThrowException($frontValue);
        } else {
            $frontend->method('getValue')->willReturn($frontValue);
        }
        $attribute = $this->getMockBuilder(\Magento\Eav\Model\Entity\Attribute::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAttributeCode', 'getStoreLabel', 'getFrontendInput', 'getFrontend', 'getIsUserDefined'])
            ->getMock();
        $attribute->setData('is_visible_on_front', $visible);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getStoreLabel')->willReturn($label);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getFrontend')->willReturn($frontend);
        $attribute->method('getIsUserDefined')->willReturn($userDefined);

        return $attribute;
    }

    public function testProductDetailListsVisibleAttributesAndGallery(): void
    {
        $attributes = [
            $this->makeAttribute('name', 'Name', true, true, 'text', 'Tee'),
            $this->makeAttribute('material', 'Material', true, true, 'text', 'Cotton'),
            $this->makeAttribute('internal', 'Internal', false, false, 'text', 'x'),
            $this->makeAttribute('flag', 'Flag', false, true, 'boolean', 'Yes'),
            $this->makeAttribute('nolabel', ' ', true, true, 'text', 'x'),
            $this->makeAttribute('empty', 'Empty', true, true, 'text', 'x'),
            $this->makeAttribute('na', 'NA', true, true, 'text', 'N/A'),
            $this->makeAttribute('broken', 'Broken', true, true, 'text', new \RuntimeException('x')),
            $this->makeAttribute('arr', 'Arr', true, true, 'text', ['x']),
            $this->makeAttribute('color', 'Colour', false, true, 'select', __('Blue')),
        ];
        $gallery = [new DataObject(['file' => '']), new DataObject(['file' => '/a.jpg']), new DataObject(['file' => '/b.jpg'])];
        $this->imageHelper->method('setImageFile')->willReturnSelf();
        $this->queueCollection([$this->makeProduct([
            'entity_id' => 3, 'sku' => 'D', 'type_id' => 'simple', 'final_price' => 1,
            'material' => 'c', 'internal' => 'x', 'flag' => '1', 'nolabel' => 'x', 'empty' => '',
            'na' => 'x', 'broken' => 'x', 'arr' => 'x', 'color' => '5',
        ], null, $attributes, $gallery)]);

        $row = $this->makeService()->productDetail(3, 1);

        $this->assertSame([
            ['code' => 'material', 'label' => 'Material', 'value' => 'Cotton'],
            ['code' => 'color', 'label' => 'Colour', 'value' => 'Blue'],
        ], $row['attributes']);
        $this->assertSame(['http://img/p.jpg', 'http://img/p.jpg'], $row['gallery']);
    }

    public function testProductDetailOfInvalidOrMissingProductIsNull(): void
    {
        $service = $this->makeService();
        $this->assertNull($service->productDetail(0, 1));
        $this->queueCollection([]);
        $this->assertNull($service->productDetail(5, 1));
    }

    public function testCatalogSnapshotFallsBackToSkuAsBarcode(): void
    {
        $this->barcodeAttribute = 'ean';
        $this->existingAttributes = ['ean'];
        $this->queueCollection([
            $this->makeProduct(['entity_id' => 1, 'sku' => 'A', 'ean' => '400', 'type_id' => 'simple', 'final_price' => 1]),
            $this->makeProduct(['entity_id' => 2, 'sku' => 'B', 'type_id' => 'simple', 'final_price' => 1]),
        ]);

        $rows = $this->makeService()->catalogSnapshot(1);

        $this->assertSame(['400', 'B'], array_column($rows, 'barcode'));
    }

    public function testProductOptionsOfMissingProductIsNull(): void
    {
        $service = $this->makeService();
        $this->assertNull($service->productOptions(-1, 1));
        $this->queueCollection([]);
        $this->assertNull($service->productOptions(4, 1));
    }

    public function testProductOptionsForSimpleProductWithCustomOptions(): void
    {
        $options = [
            new DataObject([
                'id' => 1, 'title' => 'Engraving', 'type' => 'field', 'is_require' => '0', 'price' => '2.5',
                'price_type' => '', 'sort_order' => 2, 'max_characters' => '20', 'file_extension' => ' ',
            ]),
            new DataObject([
                'id' => 2, 'title' => 'Size', 'type' => 'drop_down', 'is_require' => '1', 'price' => 0,
                'price_type' => 'percent', 'sort_order' => 1,
                'values' => [
                    new DataObject(['option_type_id' => 7, 'title' => 'L', 'price' => 3, 'price_type' => 'fixed', 'sort_order' => 2]),
                    new DataObject(['option_type_id' => 6, 'title' => 'S', 'price' => 0, 'price_type' => null, 'sort_order' => 1]),
                ],
            ]),
            'junk',
        ];
        $this->queueCollection([$this->makeProduct([
            'entity_id' => 4, 'sku' => 'MUG', 'type_id' => 'simple', 'final_price' => 9, '__options' => $options,
        ])]);

        $payload = $this->makeService()->productOptions(4, 1);

        $this->assertSame('simple', $payload['type']);
        $this->assertTrue($payload['product']['has_options']);
        $this->assertTrue($payload['product']['requires_options']);
        $this->assertSame(['Size', 'Engraving'], array_column($payload['custom_options'], 'title'));
        $this->assertSame('fixed', $payload['custom_options'][1]['price_type']);
        $this->assertSame(20, $payload['custom_options'][1]['max_characters']);
        $this->assertArrayNotHasKey('file_extension', $payload['custom_options'][1]);
        $this->assertSame([6, 7], array_column($payload['custom_options'][0]['values'], 'value_id'));
        $this->assertSame('fixed', $payload['custom_options'][0]['values'][0]['price_type']);
        $this->assertArrayNotHasKey('super_attributes', $payload);
    }

    public function testProductOptionsForConfigurableProduct(): void
    {
        $type = $this->createStub(\Magento\ConfigurableProduct\Model\Product\Type\Configurable::class);
        $type->method('getConfigurableAttributesAsArray')->willReturn([
            ['attribute_id' => 93, 'attribute_code' => 'color', 'store_label' => 'Colour', 'position' => 2, 'values' => [
                ['value_index' => 11, 'label' => 'Red'], ['label' => 'skip'],
            ]],
            ['attribute_id' => 94, 'attribute_code' => 'size', 'frontend_label' => 'Size', 'position' => 1, 'values' => [
                ['value_index' => 21, 'default_label' => 'M'],
            ]],
        ]);
        $type->method('getUsedProducts')->willReturn([new DataObject(['id' => 50]), new DataObject(['id' => 51])]);
        $parent = $this->makeProduct(['entity_id' => 5, 'sku' => 'HOOD', 'type_id' => 'configurable', 'min_price' => 20, '__type' => $type]);
        $this->queueCollection([$parent]);
        $this->queueCollection([
            $this->makeProduct(['entity_id' => 50, 'sku' => 'HOOD-R-M', 'color' => '11', 'size' => '21', 'final_price' => '22', 'qty' => 3]),
            $this->makeProduct(['entity_id' => 51, 'sku' => 'HOOD-X', 'color' => '11', 'final_price' => '22']),
        ]);

        $payload = $this->makeService()->productOptions(5, 1);

        $this->assertSame(['size', 'color'], array_column($payload['super_attributes'], 'code'));
        $this->assertSame([['value_id' => 21, 'label' => 'M']], $payload['super_attributes'][0]['values']);
        $this->assertSame('Colour', $payload['super_attributes'][1]['label']);
        $this->assertSame([[
            'id' => 50, 'sku' => 'HOOD-R-M', 'super_attribute' => ['93' => 11, '94' => 21],
            'price' => 22.0, 'salable_qty' => 3.0, 'in_stock' => true,
        ]], $payload['children']);
    }

    public function testProductOptionsForGroupedProduct(): void
    {
        $type = $this->createStub(\Magento\GroupedProduct\Model\Product\Type\Grouped::class);
        $type->method('getAssociatedProducts')->willReturn([
            new DataObject(['id' => 61, 'qty' => '2']),
            new DataObject(['id' => 60, 'qty' => null]),
            new DataObject(['id' => 62, 'qty' => '-1']),
        ]);
        $this->queueCollection([$this->makeProduct(['entity_id' => 6, 'type_id' => 'grouped', 'min_price' => 5, '__type' => $type])]);
        $this->queueCollection([
            $this->makeProduct(['entity_id' => 60, 'sku' => 'A', 'name' => 'Alpha', 'final_price' => 5]),
            $this->makeProduct(['entity_id' => 61, 'sku' => 'B', 'name' => 'Beta', 'final_price' => 6, 'qty' => '0']),
        ]);

        $payload = $this->makeService()->productOptions(6, 1);

        $this->assertSame([61, 60], array_column($payload['associated'], 'id'));
        $this->assertSame(2.0, $payload['associated'][0]['qty_default']);
        $this->assertFalse($payload['associated'][0]['in_stock']);
        $this->assertSame(0.0, $payload['associated'][1]['qty_default']);
        $this->assertNull($payload['associated'][1]['salable_qty']);
    }

    public function testProductOptionsForFixedPriceBundle(): void
    {
        $type = $this->createStub(\Magento\Bundle\Model\Product\Type::class);
        $type->method('getOptionsIds')->willReturn([1, 2]);
        $type->method('getOptionsCollection')->willReturn([
            new DataObject(['option_id' => 2, 'title' => '', 'default_title' => 'Extras', 'type' => 'checkbox', 'required' => 0, 'position' => 2]),
            new DataObject(['option_id' => 1, 'title' => 'Base', 'type' => 'radio', 'required' => 1, 'position' => 1]),
            new DataObject(['option_id' => 3, 'title' => 'Empty', 'position' => 0]),
        ]);
        $type->method('getSelectionsCollection')->willReturn([
            new DataObject([
                'option_id' => 1, 'product_id' => 70, 'selection_id' => 100, 'sku' => 'S1', 'name' => 'Small',
                'selection_price_type' => 1, 'selection_price_value' => 10, 'selection_qty' => 0,
                'selection_can_change_qty' => 1, 'is_default' => 1,
            ]),
            new DataObject([
                'option_id' => 2, 'product_id' => 71, 'selection_id' => 101, 'sku' => 'S2', 'name' => 'Sauce',
                'selection_price_type' => 0, 'selection_price_value' => 1.255, 'selection_qty' => 2,
            ]),
            new DataObject(['option_id' => 2, 'product_id' => 72, 'selection_id' => 102]),
        ]);
        $bundle = $this->makeProduct([
            'entity_id' => 7, 'type_id' => 'bundle', 'min_price' => 40, 'price_type' => 1,
            'computed_price' => 50.0, 'special_price' => '40', '__type' => $type,
        ]);
        $this->queueCollection([$bundle]);
        $this->queueCollection([
            $this->makeProduct(['entity_id' => 70, 'final_price' => 1]),
            $this->makeProduct(['entity_id' => 71, 'final_price' => 1]),
        ]);

        $payload = $this->makeService()->productOptions(7, 1);

        $this->assertSame([1, 2], array_column($payload['bundle_options'], 'option_id'));
        $this->assertSame('Extras', $payload['bundle_options'][1]['title']);
        $first = $payload['bundle_options'][0]['selections'][0];
        $this->assertSame(4.0, $first['price']);
        $this->assertSame(1.0, $first['qty']);
        $this->assertTrue($first['can_change_qty']);
        $this->assertTrue($first['is_default']);
        $this->assertSame(1.255, $payload['bundle_options'][1]['selections'][0]['price']);
        $this->assertCount(1, $payload['bundle_options'][1]['selections']);
    }
}
