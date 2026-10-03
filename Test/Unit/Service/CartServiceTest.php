<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManager;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Api\Data\RegisterInterface;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CartService;
use Panth\MagePos\Service\QuotePreparer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CartServiceTest extends TestCase
{
    private CartRepositoryInterface&MockObject $cartRepository;
    private QuoteFactory&MockObject $quoteFactory;
    private ProductRepositoryInterface&MockObject $productRepository;
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private SessionManager&MockObject $sessionManager;
    private SessionRepositoryInterface&MockObject $posSessionRepository;
    private RegisterRepositoryInterface&MockObject $registerRepository;
    private AuthService&MockObject $authService;
    private DataObjectFactory&MockObject $dataObjectFactory;
    private QuotePreparer&MockObject $quotePreparer;
    private ImageHelper&MockObject $imageHelper;
    private ?int $posSessionId = null;
    private array $buyRequests = [];

    protected function setUp(): void
    {
        $this->posSessionId = null;
        $this->buyRequests = [];
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->quoteFactory = $this->createMock(QuoteFactory::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->sessionManager = $this->createMock(SessionManager::class);
        $this->posSessionRepository = $this->createMock(SessionRepositoryInterface::class);
        $this->registerRepository = $this->createMock(RegisterRepositoryInterface::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->dataObjectFactory = $this->createMock(DataObjectFactory::class);
        $this->quotePreparer = $this->createMock(QuotePreparer::class);
        $this->imageHelper = $this->createMock(ImageHelper::class);

        $this->sessionManager->method('getData')->willReturnCallback(
            fn ($key) => $key === 'panth_pos_session_id' ? $this->posSessionId : null
        );
        $this->dataObjectFactory->method('create')->willReturnCallback(function (array $args) {
            $this->buyRequests[] = $args['data'];
            return new DataObject($args['data']);
        });
    }

    private function makeService(): CartService
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $config = $this->createStub(Config::class);
        $config->method('getDefaultCustomerGroupId')->willReturn(1);
        $config->method('getGuestEmail')->willReturn('guest@pos.test');
        $config->method('getCustomProductSku')->willReturn('pos-custom-sale');
        $config->method('getCustomProductDefaultTaxClassId')->willReturn(2);

        return new CartService(
            $this->cartRepository,
            $this->quoteFactory,
            $this->productRepository,
            $this->customerRepository,
            $storeManager,
            $this->sessionManager,
            $this->posSessionRepository,
            $this->registerRepository,
            $this->authService,
            $config,
            $this->imageHelper,
            $this->dataObjectFactory,
            $this->quotePreparer
        );
    }

    private function useRegister(int $registerId, int $storeId = 3): void
    {
        $this->posSessionId = 50;
        $session = $this->createStub(SessionInterface::class);
        $session->method('getRegisterId')->willReturn($registerId);
        $this->posSessionRepository->method('getById')->with(50)->willReturn($session);
        $register = $this->createStub(RegisterInterface::class);
        $register->method('getRegisterId')->willReturn($registerId);
        $register->method('getStoreId')->willReturn($storeId);
        $this->registerRepository->method('getById')->with($registerId)->willReturn($register);
    }

    private function makeProduct(array $data = [], ?array $orderOptions = null): Product&MockObject
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTypeInstance', 'getOptionById'])
            ->getMock();
        $product->setData($data);
        $type = $this->createStub(AbstractType::class);
        $type->method('getOrderOptions')->willReturn($orderOptions ?? []);
        $product->method('getTypeInstance')->willReturn($type);
        $product->method('getOptionById')->willReturn(null);

        return $product;
    }

    private function makeItem(array $data, ?Product $product = null): Item&MockObject
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId', 'getSku', 'getName', 'getQty', 'setQty', 'getCalculationPrice',
                'getOriginalPrice', 'getPrice', 'getProductType', 'getProduct', 'setName',
            ])
            ->getMock();
        $item->setData($data);
        $item->method('getId')->willReturnCallback(fn () => $item->getData('item_id'));
        $item->method('getSku')->willReturnCallback(fn () => $item->getData('sku'));
        $item->method('getName')->willReturnCallback(fn () => $item->getData('name'));
        $item->method('getQty')->willReturnCallback(fn () => $item->getData('qty'));
        $item->method('setQty')->willReturnCallback(function ($q) use ($item) {
            $item->setData('qty', $q);
            return $item;
        });
        $item->method('setName')->willReturnCallback(function ($n) use ($item) {
            $item->setData('name', $n);
            return $item;
        });
        $item->method('getCalculationPrice')->willReturnCallback(fn () => $item->getData('calculation_price'));
        $item->method('getOriginalPrice')->willReturnCallback(fn () => $item->getData('original_price'));
        $item->method('getPrice')->willReturnCallback(fn () => $item->getData('price'));
        $item->method('getProductType')->willReturnCallback(fn () => $item->getData('product_type'));
        $item->method('getProduct')->willReturn($product);

        return $item;
    }

    private function makeQuote(array $data = [], array $items = []): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId', 'getStoreId', 'getItemById', 'removeItem', 'removeAllItems', 'addProduct',
                'collectTotals', 'setCustomer', 'getAllVisibleItems', 'getAllItems', 'getItemsQty', 'setIsActive',
            ])
            ->getMock();
        $quote->setData($data + ['panth_pos_register_id' => 0]);
        $quote->method('getId')->willReturnCallback(fn () => $quote->getData('entity_id'));
        $quote->method('getStoreId')->willReturnCallback(fn () => $quote->getData('store_id') ?? 1);
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('getAllItems')->willReturn($items);
        $quote->method('getItemById')->willReturnCallback(static function ($id) use ($items) {
            foreach ($items as $item) {
                if ((int) $item->getData('item_id') === (int) $id) {
                    return $item;
                }
            }
            return false;
        });
        $quote->method('getItemsQty')->willReturnCallback(fn () => $quote->getData('items_qty'));
        $quote->method('collectTotals')->willReturnSelf();
        $quote->method('setIsActive')->willReturnCallback(function ($v) use ($quote) {
            $quote->setData('is_active', $v);
            return $quote;
        });

        return $quote;
    }

    private function loadQuote(Quote $quote): void
    {
        $this->cartRepository->method('get')->willReturn($quote);
    }

    public function testCreateUsesRegisterStoreAndMarksQuoteAsPos(): void
    {
        $this->useRegister(4, 3);
        $quote = $this->makeQuote();
        $this->quoteFactory->method('create')->willReturn($quote);
        $this->cartRepository->expects($this->once())->method('save')->with($quote)
            ->willReturnCallback(static function (Quote $q) {
                $q->setData('entity_id', 77);
            });
        $quote->setData('store_id', null);

        $this->assertSame(77, $this->makeService()->create());
        $this->assertSame(3, $quote->getData('store_id'));
        $this->assertSame(4, $quote->getData('panth_pos_register_id'));
        $this->assertTrue($quote->getData('is_active'));
        $this->assertTrue($quote->getData('customer_is_guest'));
        $this->assertSame(1, $quote->getData('customer_group_id'));
        $this->assertSame('guest@pos.test', $quote->getData('customer_email'));
    }

    public function testCreateWithoutRegisterUsesCurrentStoreAndZeroMarker(): void
    {
        $quote = $this->makeQuote();
        $this->quoteFactory->method('create')->willReturn($quote);

        $this->makeService()->create();

        $this->assertSame(1, $quote->getData('store_id'));
        $this->assertSame(0, $quote->getData('panth_pos_register_id'));
    }

    public function testNonPosQuoteIsReportedAsNotFound(): void
    {
        $quote = $this->makeQuote(['entity_id' => 9]);
        $quote->unsetData('panth_pos_register_id');
        $this->loadQuote($quote);

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Cart "9" was not found.');
        $this->makeService()->get(9);
    }

    public function testQuoteOfAnotherRegisterIsReportedAsNotFound(): void
    {
        $this->useRegister(4);
        $this->loadQuote($this->makeQuote(['entity_id' => 9, 'panth_pos_register_id' => 5]));

        $this->expectException(NoSuchEntityException::class);
        $this->makeService()->get(9);
    }

    public function testQuoteOfSameRegisterIsReturned(): void
    {
        $this->useRegister(4);
        $this->loadQuote($this->makeQuote(['entity_id' => 9, 'panth_pos_register_id' => '4']));

        $this->assertSame(9, $this->makeService()->get(9)['quote_id']);
    }

    public function testBuildCartPayloadSummarisesItemsTotalsCustomerAndDiscount(): void
    {
        $product = $this->makeProduct([], [
            'attributes_info' => [['label' => 'Size', 'value' => 'M'], ['broken']],
            'bundle_options' => [[
                'label' => 'Extras',
                'value' => [['title' => 'Sauce', 'qty' => 2.5], ['title' => 'Cup', 'qty' => 1], ['title' => '']],
            ]],
            'options' => [
                ['label' => 'Engraving', 'print_value' => 'Hi'],
                ['label' => 'Colours', 'value' => ['Red', 'Blue']],
                ['label' => 'Empty', 'value' => '  '],
            ],
        ]);
        $this->imageHelper->method('init')->willReturnSelf();
        $this->imageHelper->method('resize')->with(200, 200)->willReturnSelf();
        $this->imageHelper->method('getUrl')->willReturn('http://img/thumb.jpg');

        $regular = $this->makeItem([
            'item_id' => 1, 'product_id' => 10, 'sku' => 'TEE', 'name' => 'Tee', 'qty' => 2,
            'calculation_price' => 10, 'original_price' => 12, 'row_total' => 20, 'discount_amount' => 1.5,
            'tax_amount' => 3.333, 'product_type' => 'configurable',
            'additional_data' => json_encode(['pos_note' => 'gift wrap']),
        ], $product);
        $custom = $this->makeItem([
            'item_id' => 2, 'product_id' => 11, 'sku' => 'pos-custom-sale', 'name' => 'Placeholder', 'qty' => 1,
            'calculation_price' => 5, 'original_price' => 0, 'price' => 4, 'row_total' => 5,
            'product_type' => 'virtual',
            'additional_data' => json_encode(['pos_custom_name' => 'Repair']),
        ], $this->makeProduct());
        $quote = $this->makeQuote([
            'entity_id' => 9, 'subtotal' => 25, 'grand_total' => 30.456, 'items_qty' => 3,
            'customer_id' => 4, 'customer_firstname' => 'Ann', 'customer_lastname' => 'Lee',
            'customer_email' => 'ann@x.test', 'customer_group_id' => 2, 'coupon_code' => 'SAVE',
            'panth_pos_discount_type' => 'percent', 'panth_pos_discount_value' => '10',
            'panth_pos_note' => '  deliver later ',
        ], [$regular, $custom]);

        $payload = $this->makeService()->buildCartPayload($quote);

        $this->assertSame(9, $payload['quote_id']);
        $this->assertSame([
            'subtotal' => 25.0, 'discount' => 1.5, 'pos_discount' => 2.5, 'tax' => 3.33,
            'grand_total' => 30.46, 'items_qty' => 3.0,
        ], $payload['totals']);
        $this->assertSame(['id' => 4, 'name' => 'Ann Lee', 'email' => 'ann@x.test', 'group' => 2], $payload['customer']);
        $this->assertSame('SAVE', $payload['coupon_code']);
        $this->assertSame(['type' => 'percent', 'value' => 10.0], $payload['pos_discount']);
        $this->assertSame('deliver later', $payload['note']);

        $first = $payload['items'][0];
        $this->assertSame('gift wrap', $first['note']);
        $this->assertFalse($first['is_custom']);
        $this->assertSame(12.0, $first['original_price']);
        $this->assertSame('http://img/thumb.jpg', $first['image']);
        $this->assertSame([
            ['label' => 'Size', 'value' => 'M'],
            ['label' => 'Extras', 'value' => "2.5 \u{00d7} Sauce, 1 \u{00d7} Cup"],
            ['label' => 'Engraving', 'value' => 'Hi'],
            ['label' => 'Colours', 'value' => 'Red, Blue'],
        ], $first['options']);

        $second = $payload['items'][1];
        $this->assertTrue($second['is_custom']);
        $this->assertSame('Repair', $second['name']);
        $this->assertSame(4.0, $second['original_price']);
        $this->assertNull($second['image']);
        $this->assertSame([], $second['options']);
        $this->assertNull($second['note']);
    }

    public static function posDiscountProvider(): array
    {
        return [
            'fixed under subtotal' => ['fixed', '5', 20.0, 5.0],
            'fixed capped at subtotal' => ['fixed', '50', 20.0, 20.0],
            'fixed on negative subtotal' => ['fixed', '5', -3.0, 0.0],
            'percent' => ['percent', '12.5', 20.0, 2.5],
        ];
    }

    #[DataProvider('posDiscountProvider')]
    public function testPosDiscountAmount(string $type, string $value, float $subtotal, float $expected): void
    {
        $quote = $this->makeQuote([
            'subtotal' => $subtotal,
            'panth_pos_discount_type' => $type,
            'panth_pos_discount_value' => $value,
        ]);

        $this->assertSame($expected, $this->makeService()->buildCartPayload($quote)['totals']['pos_discount']);
    }

    public function testGuestCartHasNoCustomerNoteCouponOrPosDiscount(): void
    {
        $payload = $this->makeService()->buildCartPayload($this->makeQuote(['panth_pos_discount_type' => 'fixed']));

        $this->assertNull($payload['customer']);
        $this->assertNull($payload['coupon_code']);
        $this->assertNull($payload['pos_discount']);
        $this->assertNull($payload['note']);
        $this->assertSame([], $payload['items']);
    }

    public function testAddProductRejectsNonPositiveQty(): void
    {
        $this->cartRepository->expects($this->never())->method('get');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Quantity must be greater than zero.');
        $this->makeService()->addProduct(9, 5, 0);
    }

    public function testAddProductSanitisesBuyRequestAndCollectsTotals(): void
    {
        $quote = $this->makeQuote(['entity_id' => 9]);
        $this->loadQuote($quote);
        $product = $this->makeProduct();
        $this->productRepository->expects($this->once())->method('getById')->with(5, false, 1)->willReturn($product);
        $quote->expects($this->once())->method('addProduct')->with($product)->willReturn($this->makeItem([]));
        $quote->expects($this->once())->method('collectTotals');
        $this->quotePreparer->expects($this->once())->method('prepare')->with($quote, false);
        $this->cartRepository->expects($this->once())->method('save')->with($quote);

        $this->makeService()->addProduct(9, 5, 2, [
            'super_attribute' => ['93' => '52', '0' => '1', '94' => 'x', '95' => '0'],
            'super_group' => ['7' => '1.5', '8' => '-1', 'a' => '2'],
            'bundle_option' => ['1' => ['3', 'x', '0'], '2' => '4', '0' => '9', '3' => ['bad'], '4' => 'z'],
            'bundle_option_qty' => ['1' => '2', '2' => '0'],
            'custom_options' => ['5' => 'text', '6' => ['a'], '0' => 'skip', '7' => null],
        ]);

        $this->assertSame([
            'qty' => 2.0,
            'super_attribute' => [93 => 52],
            'super_group' => [7 => 1.5],
            'bundle_option' => [1 => [3], 2 => 4],
            'bundle_option_qty' => [1 => 2.0],
            'options' => [5 => 'text', 6 => ['a']],
        ], $this->buyRequests[0]);
        $this->assertFalse($quote->getData('totals_collected_flag'));
    }

    public function testAddProductBySkuFallsBackToNumericIdLookup(): void
    {
        $quote = $this->makeQuote();
        $this->loadQuote($quote);
        $product = $this->makeProduct();
        $this->productRepository->method('get')->with('42', false, 1)
            ->willThrowException(new NoSuchEntityException(__('no sku')));
        $this->productRepository->expects($this->once())->method('getById')->with(42, false, 1)->willReturn($product);
        $quote->method('addProduct')->willReturn($this->makeItem([]));

        $this->makeService()->addProduct(9, '42', 1, ['options' => ['3' => 'x']]);

        $this->assertSame(['qty' => 1.0, 'options' => [3 => 'x']], $this->buyRequests[0]);
    }

    public function testAddProductByUnknownNonNumericSkuRethrows(): void
    {
        $this->loadQuote($this->makeQuote());
        $this->productRepository->method('get')->willThrowException(new NoSuchEntityException(__('no sku')));
        $this->productRepository->expects($this->never())->method('getById');

        $this->expectException(NoSuchEntityException::class);
        $this->makeService()->addProduct(9, 'ABC', 1);
    }

    public function testAddProductSurfacesStringErrorFromQuote(): void
    {
        $quote = $this->makeQuote();
        $this->loadQuote($quote);
        $this->productRepository->method('get')->willReturn($this->makeProduct());
        $quote->method('addProduct')->willReturn('Please specify product option(s).');
        $this->cartRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Please specify product option(s).');
        $this->makeService()->addProduct(9, 'TEE', 1);
    }

    public function testUpdateItemOfUnknownItemThrows(): void
    {
        $this->loadQuote($this->makeQuote());

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Cart item "5" was not found.');
        $this->makeService()->updateItem(9, 5, 1, null, null);
    }

    public function testUpdateItemPriceOverrideRequiresPermission(): void
    {
        $item = $this->makeItem(['item_id' => 5]);
        $this->loadQuote($this->makeQuote([], [$item]));
        $this->authService->method('requirePermission')->with('can_price_override')
            ->willThrowException(new AuthorizationException(__('denied')));

        $this->expectException(AuthorizationException::class);
        $this->makeService()->updateItem(9, 5, null, 3.0, null);
    }

    public function testUpdateItemRejectsNegativePrice(): void
    {
        $item = $this->makeItem(['item_id' => 5]);
        $this->loadQuote($this->makeQuote([], [$item]));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Price cannot be negative.');
        $this->makeService()->updateItem(9, 5, null, -1.0, null);
    }

    public function testUpdateItemAppliesPriceNoteAndQty(): void
    {
        $product = $this->makeProduct();
        $item = $this->makeItem(['item_id' => 5, 'qty' => 1, 'additional_data' => '{"pos_custom":true}'], $product);
        $this->loadQuote($this->makeQuote([], [$item]));
        $this->authService->expects($this->once())->method('requirePermission')->with('can_price_override');

        $this->makeService()->updateItem(9, 5, 3.0, 7.5, 'no onions');

        $this->assertSame(7.5, $item->getData('custom_price'));
        $this->assertSame(7.5, $item->getData('original_custom_price'));
        $this->assertTrue($product->getData('is_super_mode'));
        $this->assertSame(3.0, $item->getData('qty'));
        $this->assertSame(['pos_custom' => true, 'pos_note' => 'no onions'], json_decode($item->getData('additional_data'), true));
    }

    public function testUpdateItemEmptyNoteClearsAdditionalDataAndZeroQtyRemovesLine(): void
    {
        $item = $this->makeItem(['item_id' => 5, 'qty' => 1, 'additional_data' => '{"pos_note":"x"}']);
        $quote = $this->makeQuote([], [$item]);
        $this->loadQuote($quote);
        $quote->expects($this->once())->method('removeItem')->with(5);
        $this->authService->expects($this->never())->method('requirePermission');

        $this->makeService()->updateItem(9, 5, 0.0, null, '');

        $this->assertNull($item->getData('additional_data'));
        $this->assertSame(1, $item->getData('qty'));
    }

    public function testRemoveItemAndClearDelegateToQuote(): void
    {
        $quote = $this->makeQuote();
        $this->loadQuote($quote);
        $quote->expects($this->once())->method('removeItem')->with(3);
        $quote->expects($this->once())->method('removeAllItems');
        $this->cartRepository->expects($this->exactly(2))->method('save');

        $service = $this->makeService();
        $service->removeItem(9, 3);
        $service->clear(9);
    }

    public function testSetCustomerAssignsCustomerIdentity(): void
    {
        $quote = $this->makeQuote();
        $this->loadQuote($quote);
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('ann@x.test');
        $customer->method('getFirstname')->willReturn('Ann');
        $customer->method('getLastname')->willReturn('Lee');
        $customer->method('getGroupId')->willReturn(3);
        $this->customerRepository->method('getById')->with(4)->willReturn($customer);
        $quote->expects($this->once())->method('setCustomer')->with($customer);

        $this->makeService()->setCustomer(9, 4);

        $this->assertFalse($quote->getData('customer_is_guest'));
        $this->assertSame('ann@x.test', $quote->getData('customer_email'));
        $this->assertSame('Ann', $quote->getData('customer_firstname'));
        $this->assertSame(3, $quote->getData('customer_group_id'));
    }

    public function testSetCustomerNullRevertsToGuest(): void
    {
        $quote = $this->makeQuote(['customer_id' => 4, 'customer_firstname' => 'Ann', 'customer_group_id' => 3]);
        $this->loadQuote($quote);
        $this->customerRepository->expects($this->never())->method('getById');

        $payload = $this->makeService()->setCustomer(9, null);

        $this->assertNull($quote->getData('customer_id'));
        $this->assertTrue($quote->getData('customer_is_guest'));
        $this->assertSame('guest@pos.test', $quote->getData('customer_email'));
        $this->assertNull($quote->getData('customer_firstname'));
        $this->assertSame(1, $quote->getData('customer_group_id'));
        $this->assertNull($payload['customer']);
    }

    public static function invalidCustomItemProvider(): array
    {
        return [
            'blank name' => ['  ', 1.0, 1.0, 'Custom item name is required.'],
            'negative price' => ['Fee', -1.0, 1.0, 'Price cannot be negative.'],
            'zero qty' => ['Fee', 1.0, 0.0, 'Quantity must be greater than zero.'],
        ];
    }

    #[DataProvider('invalidCustomItemProvider')]
    public function testAddCustomItemValidatesInput(string $name, float $price, float $qty, string $message): void
    {
        $this->authService->expects($this->once())->method('requirePermission')->with('can_custom_product');
        $this->cartRepository->expects($this->never())->method('get');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);
        $this->makeService()->addCustomItem(9, $name, $price, $qty, null);
    }

    public function testAddCustomItemFailsWhenPlaceholderProductMissing(): void
    {
        $this->loadQuote($this->makeQuote());
        $this->productRepository->method('get')->with('pos-custom-sale', false, 1, true)
            ->willThrowException(new NoSuchEntityException(__('missing')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The POS custom sale placeholder product "pos-custom-sale" does not exist.');
        $this->makeService()->addCustomItem(9, 'Fee', 1.0, 1.0, null);
    }

    public function testAddCustomItemNamesAndPricesTheLine(): void
    {
        $quote = $this->makeQuote();
        $this->loadQuote($quote);
        $product = $this->makeProduct();
        $this->productRepository->method('get')->willReturn($product);
        $lineProduct = $this->makeProduct();
        $line = $this->makeItem(['item_id' => 8], $lineProduct);
        $quote->method('addProduct')->with($product)->willReturn($line);

        $this->makeService()->addCustomItem(9, ' Repair ', 15.0, 2.0, null);

        $this->assertSame(2, $product->getData('tax_class_id'));
        $this->assertSame(2.0, $this->buyRequests[0]['qty']);
        $this->assertStringStartsWith('pos_', $this->buyRequests[0]['pos_custom_uid']);
        $this->assertSame('Repair', $line->getData('name'));
        $this->assertSame(15.0, $line->getData('custom_price'));
        $this->assertTrue($lineProduct->getData('is_super_mode'));
        $this->assertSame(
            ['pos_custom' => true, 'pos_custom_name' => 'Repair', 'pos_tax_class_id' => 2],
            json_decode($line->getData('additional_data'), true)
        );
    }

    public function testAddCustomItemHonoursExplicitTaxClass(): void
    {
        $quote = $this->makeQuote();
        $this->loadQuote($quote);
        $product = $this->makeProduct();
        $this->productRepository->method('get')->willReturn($product);
        $line = $this->makeItem(['item_id' => 8]);
        $quote->method('addProduct')->willReturn($line);

        $this->makeService()->addCustomItem(9, 'Fee', 1.0, 1.0, 0);

        $this->assertSame(0, $product->getData('tax_class_id'));
        $this->assertSame(0, json_decode($line->getData('additional_data'), true)['pos_tax_class_id']);
    }

    public function testSetNoteTrimsAndSavesWithoutCollectingTotals(): void
    {
        $quote = $this->makeQuote(['entity_id' => 9]);
        $this->loadQuote($quote);
        $quote->expects($this->never())->method('collectTotals');
        $this->cartRepository->expects($this->once())->method('save')->with($quote);

        $payload = $this->makeService()->setNote(9, '  call me  ');

        $this->assertSame('call me', $payload['note']);
    }

    public function testImageFailureYieldsNullImage(): void
    {
        $this->imageHelper->method('init')->willThrowException(new \RuntimeException('no image'));
        $item = $this->makeItem(['item_id' => 1, 'sku' => 'A'], $this->makeProduct());

        $payload = $this->makeService()->buildCartPayload($this->makeQuote([], [$item]));

        $this->assertNull($payload['items'][0]['image']);
    }
}
