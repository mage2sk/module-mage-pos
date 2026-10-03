<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CartService;
use Panth\MagePos\Service\DiscountService;
use PHPUnit\Framework\MockObject\MockObject;
use Panth\MagePos\Test\Unit\MagicCallsTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DiscountServiceTest extends TestCase
{
    use MagicCallsTrait;

    private const QUOTE_ID = 11;

    private array $quoteSetData = [];

    private array $customPrices = [];

    private array $additionalDataWrites = [];

    private array $quoteAddresses = [];

    protected function setUp(): void
    {
        $this->quoteSetData = [];
        $this->customPrices = [];
        $this->additionalDataWrites = [];
        $this->quoteAddresses = [];
    }

    public function testApplyCartDiscountPercentWithinCapIsPersistedOnQuote(): void
    {
        $quote = $this->makeQuote(200.0);
        $service = $this->makeService($quote, 15.0);

        $payload = $service->applyCartDiscount(self::QUOTE_ID, 'percent', 12.5);

        $this->assertSame(
            [
                [DiscountService::QUOTE_DISCOUNT_TYPE, DiscountService::TYPE_PERCENT],
                [DiscountService::QUOTE_DISCOUNT_VALUE, 12.5],
            ],
            $this->quoteSetData
        );
        $this->assertSame(['quote_id' => self::QUOTE_ID], $payload);
    }

    public function testApplyCartDiscountAcceptsUppercaseTypeAlias(): void
    {
        $quote = $this->makeQuote(200.0);
        $service = $this->makeService($quote, 100.0);

        $service->applyCartDiscount(self::QUOTE_ID, ' PERCENT ', 10.0);

        $this->assertSame(DiscountService::TYPE_PERCENT, $this->quoteSetData[0][1]);
    }

    public function testApplyCartDiscountFixedWithinCapStoresAmount(): void
    {
        $quote = $this->makeQuote(200.0);
        $service = $this->makeService($quote, 20.0);

        $service->applyCartDiscount(self::QUOTE_ID, 'fixed', 30.0);

        $this->assertSame(
            [
                [DiscountService::QUOTE_DISCOUNT_TYPE, DiscountService::TYPE_FIXED],
                [DiscountService::QUOTE_DISCOUNT_VALUE, 30.0],
            ],
            $this->quoteSetData
        );
    }

    public function testApplyCartDiscountFixedIsCapCheckedAsEffectivePercent(): void
    {
        $quote = $this->makeQuote(200.0);
        $service = $this->makeService($quote, 20.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/exceeds your allowed maximum/');
        $service->applyCartDiscount(self::QUOTE_ID, 'fixed', 50.0);
    }

    public function testApplyCartDiscountFixedCannotExceedSubtotal(): void
    {
        $quote = $this->makeQuote(40.0);
        $service = $this->makeService($quote, 100.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('A fixed discount cannot exceed the cart subtotal.');
        $service->applyCartDiscount(self::QUOTE_ID, 'fixed', 50.0);
    }

    public function testApplyCartDiscountPercentCapIsCheckedAgainstPostRuleSubtotal(): void
    {
        $quote = $this->makeQuote(200.0, null, 100.0);
        $service = $this->makeService($quote, 20.0);

        $service->applyCartDiscount(self::QUOTE_ID, 'percent', 20.0);

        $this->assertSame(
            [
                [DiscountService::QUOTE_DISCOUNT_TYPE, DiscountService::TYPE_PERCENT],
                [DiscountService::QUOTE_DISCOUNT_VALUE, 20.0],
            ],
            $this->quoteSetData
        );
    }

    public function testApplyCartDiscountFixedCapUsesPostRuleSubtotalNotCatalogSubtotal(): void
    {
        $quote = $this->makeQuote(200.0, null, 100.0);
        $service = $this->makeService($quote, 20.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/30%.*exceeds your allowed maximum.*20%/');
        $service->applyCartDiscount(self::QUOTE_ID, 'fixed', 30.0);
    }

    public function testApplyCartDiscountFixedCannotExceedPostRuleSubtotal(): void
    {
        $quote = $this->makeQuote(100.0, null, 60.0);
        $service = $this->makeService($quote, 100.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('A fixed discount cannot exceed the cart subtotal.');
        $service->applyCartDiscount(self::QUOTE_ID, 'fixed', 50.0);
    }

    public function testApplyCartDiscountFixedWithinPostRuleSubtotalIsAccepted(): void
    {
        $quote = $this->makeQuote(200.0, null, 100.0);
        $service = $this->makeService($quote, 20.0);

        $service->applyCartDiscount(self::QUOTE_ID, 'fixed', 10.0);

        $this->assertSame(
            [
                [DiscountService::QUOTE_DISCOUNT_TYPE, DiscountService::TYPE_FIXED],
                [DiscountService::QUOTE_DISCOUNT_VALUE, 10.0],
            ],
            $this->quoteSetData
        );
    }

    public function testApplyCartDiscountRejectedWhenRulesAlreadyDiscountToZero(): void
    {
        $quote = $this->makeQuote(100.0, null, 100.0);
        $service = $this->makeService($quote, 100.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/already discount this cart to zero/');
        $service->applyCartDiscount(self::QUOTE_ID, 'percent', 10.0);
    }

    public function testApplyCartDiscountRejectsUnknownType(): void
    {
        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $quoteRepository->expects($this->never())->method('getActive');
        $service = new DiscountService(
            $quoteRepository,
            $this->makeAuthService(100.0),
            $this->createMock(CartService::class)
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Discount type must be "percent" or "fixed".');
        $service->applyCartDiscount(self::QUOTE_ID, 'bogus', 10.0);
    }

    public function testApplyCartDiscountRejectsNonPositiveValue(): void
    {
        $service = $this->makeService($this->makeQuote(100.0), 100.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The discount value must be greater than zero.');
        $service->applyCartDiscount(self::QUOTE_ID, 'percent', 0.0);
    }

    public function testApplyCartDiscountRejectsPercentOverOneHundred(): void
    {
        $service = $this->makeService($this->makeQuote(100.0), 100.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('A percentage discount cannot exceed 100%.');
        $service->applyCartDiscount(self::QUOTE_ID, 'percent', 100.01);
    }

    public function testApplyCartDiscountRejectsEmptyCart(): void
    {
        $service = $this->makeService($this->makeQuote(0.0), 100.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('A discount cannot be applied to an empty cart.');
        $service->applyCartDiscount(self::QUOTE_ID, 'percent', 10.0);
    }

    public function testRemoveCartDiscountClearsBothQuoteColumns(): void
    {
        $quote = $this->makeQuote(200.0);
        $service = $this->makeService($quote, 100.0);

        $service->removeCartDiscount(self::QUOTE_ID);

        $this->assertSame(
            [
                [DiscountService::QUOTE_DISCOUNT_TYPE, null],
                [DiscountService::QUOTE_DISCOUNT_VALUE, null],
            ],
            $this->quoteSetData
        );
    }

    public function testApplyItemDiscountPercentReducesUnitPrice(): void
    {
        $item = $this->makeItem(2.0, 50.0);
        $service = $this->makeService($this->makeQuote(100.0, $item), 100.0);

        $service->applyItemDiscount(self::QUOTE_ID, 21, 'percent', 10.0);

        $this->assertSame([45.0], $this->customPrices);
        $written = json_decode((string)$this->additionalDataWrites[0], true);
        $this->assertSame(50.0, (float)$written['panth_pos_original_price']);
        $this->assertFalse($written['panth_pos_baseline_is_custom']);
        $this->assertSame(['type' => 'percent', 'value' => 10.0], [
            'type' => $written['panth_pos_item_discount']['type'],
            'value' => (float)$written['panth_pos_item_discount']['value'],
        ]);
    }

    public function testApplyItemDiscountFixedIsSpreadEvenlyAcrossQty(): void
    {
        $item = $this->makeItem(2.0, 50.0);
        $service = $this->makeService($this->makeQuote(100.0, $item), 25.0);

        $service->applyItemDiscount(self::QUOTE_ID, 21, 'fixed', 20.0);

        $this->assertSame([40.0], $this->customPrices);
    }

    public function testApplyItemDiscountFixedCannotExceedLineTotal(): void
    {
        $item = $this->makeItem(2.0, 50.0);
        $service = $this->makeService($this->makeQuote(100.0, $item), 100.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('A fixed discount cannot exceed the line total.');
        $service->applyItemDiscount(self::QUOTE_ID, 21, 'fixed', 120.0);
    }

    public function testApplyItemDiscountEnforcesRoleCap(): void
    {
        $item = $this->makeItem(1.0, 100.0);
        $service = $this->makeService($this->makeQuote(100.0, $item), 10.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/25%.*exceeds your allowed maximum.*10%/');
        $service->applyItemDiscount(self::QUOTE_ID, 21, 'percent', 25.0);
    }

    public function testReappliedItemDiscountNeverCompounds(): void
    {
        $existing = json_encode([
            'panth_pos_original_price' => 50.0,
            'panth_pos_baseline_is_custom' => false,
            'panth_pos_item_discount' => ['type' => 'percent', 'value' => 10.0],
        ]);
        $item = $this->makeItem(1.0, 50.0, 45.0, (string)$existing);
        $service = $this->makeService($this->makeQuote(100.0, $item), 100.0);

        $service->applyItemDiscount(self::QUOTE_ID, 21, 'percent', 10.0);

        $this->assertSame([45.0], $this->customPrices);
    }

    public function testRemoveItemDiscountRestoresCatalogPriceLine(): void
    {
        $existing = json_encode([
            'panth_pos_original_price' => 50.0,
            'panth_pos_baseline_is_custom' => false,
            'panth_pos_item_discount' => ['type' => 'percent', 'value' => 10.0],
        ]);
        $item = $this->makeItem(1.0, 50.0, 45.0, (string)$existing);
        $service = $this->makeService($this->makeQuote(100.0, $item), 100.0);

        $service->removeItemDiscount(self::QUOTE_ID, 21);

        $this->assertSame([null], $this->customPrices);
        $this->assertSame([null], $this->additionalDataWrites);
    }

    public function testApplyItemDiscountToUnknownItemThrows(): void
    {
        $service = $this->makeService($this->makeQuote(100.0), 100.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Cart item 21 was not found.');
        $service->applyItemDiscount(self::QUOTE_ID, 21, 'percent', 10.0);
    }

    public function testApplyCouponRequiresNonEmptyCode(): void
    {
        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $quoteRepository->expects($this->never())->method('getActive');
        $service = new DiscountService(
            $quoteRepository,
            $this->makeAuthService(100.0),
            $this->createMock(CartService::class)
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('A coupon code is required.');
        $service->applyCoupon(self::QUOTE_ID, '   ');
    }

    public function testApplyCouponExceedingCapIsRolledBack(): void
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();
        $this->stubMagicCalls($address, [
            'setCollectShippingRates' => static fn () => $address,
            'getDiscountAmount' => -30.0,
        ]);

        $couponCodes = [];
        $couponCodeReads = ['', 'SAVE30'];
        $quote = $this->makeQuote(100.0, null, 0.0, [
            'setCouponCode' => function ($code) use (&$couponCodes) {
                $couponCodes[] = $code;
            },
            'getCouponCode' => function () use (&$couponCodeReads) {
                return array_shift($couponCodeReads);
            },
        ]);
        $this->quoteAddresses = [$address];
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('getShippingAddress')->willReturn($address);

        $service = $this->makeService($quote, 20.0);

        try {
            $service->applyCoupon(self::QUOTE_ID, 'SAVE30');
            $this->fail('Expected the over-cap coupon to be rejected.');
        } catch (LocalizedException $e) {
            $this->assertMatchesRegularExpression('/exceeds your allowed maximum/', (string)$e->getMessage());
        }

        $this->assertSame(['SAVE30', ''], $couponCodes);
    }

    public function testApplyCouponWithinCapStacksOnTopOfRuleSubtotal(): void
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();
        $this->stubMagicCalls($address, [
            'setCollectShippingRates' => static fn () => $address,
            'getDiscountAmount' => -10.0,
        ]);

        $couponCodes = [];
        $couponCodeReads = ['', 'SAVE10'];
        $quote = $this->makeQuote(100.0, null, 0.0, [
            'setCouponCode' => function ($code) use (&$couponCodes) {
                $couponCodes[] = $code;
            },
            'getCouponCode' => function () use (&$couponCodeReads) {
                return array_shift($couponCodeReads);
            },
        ]);
        $this->quoteAddresses = [$address];
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('getShippingAddress')->willReturn($address);

        $service = $this->makeService($quote, 20.0);

        $payload = $service->applyCoupon(self::QUOTE_ID, 'SAVE10');

        $this->assertSame(['SAVE10'], $couponCodes);
        $this->assertSame(['quote_id' => self::QUOTE_ID], $payload);
    }

    private function makeService(Quote&MockObject $quote, float $maxDiscountPercent): DiscountService
    {
        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $quoteRepository->method('getActive')->with(self::QUOTE_ID)->willReturn($quote);

        $cartService = $this->createMock(CartService::class);
        $cartService->method('get')->with(self::QUOTE_ID)->willReturn(['quote_id' => self::QUOTE_ID]);

        return new DiscountService($quoteRepository, $this->makeAuthService($maxDiscountPercent), $cartService);
    }

    private function makeAuthService(float $maxDiscountPercent): AuthService&MockObject
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getMaxDiscountPercent')->willReturn($maxDiscountPercent);

        return $authService;
    }

    private function makeQuote(
        float $subtotal,
        ?Item $item = null,
        float $ruleDiscount = 0.0,
        array $magicCalls = []
    ): Quote&MockObject {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getItemById',
                'getAllVisibleItems',
                'collectTotals',
                'getItemsCount',
                'getAllAddresses',
                'getShippingAddress',
                'setData',
                '__call',
            ])
            ->getMock();

        $this->stubMagicCalls($quote, array_merge([
            'getSubtotal' => $subtotal,
            'setTotalsCollectedFlag' => null,
            'getCouponCode' => null,
            'setCouponCode' => null,
        ], $magicCalls));
        $quote->method('getAllVisibleItems')->willReturn($item !== null ? [$item] : []);
        $quote->method('getItemById')->willReturnCallback(
            static fn ($itemId) => (int)$itemId === 21 ? $item : null
        );
        $quote->method('collectTotals')->willReturnSelf();
        $quote->method('setData')->willReturnCallback(function ($key, $value = null) {
            $this->quoteSetData[] = [$key, $value];
        });

        if ($ruleDiscount > 0) {
            $this->quoteAddresses = [$this->makeRuleDiscountAddress($ruleDiscount)];
        }
        $quote->method('getAllAddresses')->willReturnCallback(fn () => $this->quoteAddresses);

        return $quote;
    }

    private function makeRuleDiscountAddress(float $ruleDiscount): Address&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();
        $this->stubMagicCalls($address, ['getDiscountAmount' => -1 * abs($ruleDiscount)]);

        return $address;
    }

    private function makeItem(
        float $qty,
        float $price,
        ?float $customPrice = null,
        ?string $additionalData = null
    ): Item&MockObject {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQty', 'getPrice', 'setCustomPrice', 'isDeleted', 'getProduct', '__call'])
            ->getMock();

        $item->method('getQty')->willReturn($qty);
        $item->method('getPrice')->willReturn($price);
        $item->method('isDeleted')->willReturn(false);
        $item->method('getProduct')->willReturn(null);
        $item->method('setCustomPrice')->willReturnCallback(function ($value) {
            $this->customPrices[] = $value;
        });
        $this->stubMagicCalls($item, [
            'getParentItemId' => null,
            'setOriginalCustomPrice' => static fn () => $item,
            'getCustomPrice' => $customPrice,
            'getAdditionalData' => $additionalData,
            'setAdditionalData' => function ($value) {
                $this->additionalDataWrites[] = $value;
            },
        ]);

        return $item;
    }
}
