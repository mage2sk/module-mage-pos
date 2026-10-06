<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CartService;
use Panth\MagePos\Service\DiscountService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DiscountServiceEdgeCasesTest extends TestCase
{
    private CartRepositoryInterface&MockObject $quoteRepository;
    private AuthService&MockObject $authService;
    private CartService&MockObject $cartService;

    protected function setUp(): void
    {
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->cartService = $this->createMock(CartService::class);
        $this->cartService->method('get')->willReturnCallback(static fn (int $id) => ['quote_id' => $id]);
    }

    private function service(): DiscountService
    {
        return new DiscountService($this->quoteRepository, $this->authService, $this->cartService);
    }

    private function makeQuote(array $data = [], array $items = [], float $ruleDiscount = 0.0, ?\Closure $onCollect = null): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'collectTotals', 'getItemById', 'getAllAddresses', 'getAllVisibleItems', 'getItemsCount'])
            ->getMock();
        $quote->setData($data);
        $shipping = $this->getMockBuilder(Address::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote->method('getShippingAddress')->willReturn($shipping);
        $quote->method('getItemById')->willReturnCallback(static fn ($id) => $items[$id] ?? false);
        $quote->method('getAllAddresses')->willReturn([new DataObject(['discount_amount' => -$ruleDiscount])]);
        $quote->method('getAllVisibleItems')->willReturn(array_values($items));
        $quote->method('getItemsCount')->willReturn(count($items));
        $quote->method('collectTotals')->willReturnCallback(function () use ($quote, $onCollect) {
            if ($onCollect !== null) {
                $onCollect($quote);
            }
            return $quote;
        });
        $this->quoteRepository->method('getActive')->willReturn($quote);

        return $quote;
    }

    private function makeItem(array $data): Item
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $item->setData($data);

        return $item;
    }

    public function testMissingCartIsReportedAsGone(): void
    {
        $this->quoteRepository->method('getActive')->willThrowException(new NoSuchEntityException(__('x')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Cart 4 no longer exists.');
        $this->service()->removeCartDiscount(4);
    }

    public function testNonQuoteCartCannotBeLoaded(): void
    {
        $this->quoteRepository->method('getActive')->willReturn($this->createStub(CartInterface::class));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unable to load cart 4.');
        $this->service()->removeCoupon(4);
    }

    public function testCartOfAnotherRegisterIsRejectedByPosGuard(): void
    {
        $this->makeQuote();
        $this->cartService->method('assertPosQuote')->willThrowException(new NoSuchEntityException(__('Cart "4" was not found.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Cart 4 no longer exists.');
        $this->service()->removeCoupon(4);
    }

    public function testRemoveCouponClearsCodeAndRecollects(): void
    {
        $quote = $this->makeQuote(['coupon_code' => 'SAVE']);
        $quote->expects($this->once())->method('collectTotals');
        $this->cartService->expects($this->once())->method('prepareQuote')->with($quote);
        $this->quoteRepository->expects($this->once())->method('save')->with($quote);

        $this->assertSame(['quote_id' => 4], $this->service()->removeCoupon(4));
        $this->assertSame('', $quote->getCouponCode());
        $this->assertTrue((bool) $quote->getShippingAddress()->getCollectShippingRates());
        $this->assertFalse($quote->getData('totals_collected_flag'));
    }

    public function testRemoveCouponFailureIsWrapped(): void
    {
        $this->makeQuote();
        $this->quoteRepository->method('save')->willThrowException(new \RuntimeException('deadlock'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The coupon code could not be removed. Please try again.');
        $this->service()->removeCoupon(4);
    }

    public function testCouponOnEmptyCartIsRejected(): void
    {
        $this->makeQuote();
        $this->quoteRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('A coupon cannot be applied to an empty cart.');
        $this->service()->applyCoupon(4, 'SAVE');
    }

    public function testInvalidCouponIsRolledBackToPreviousCode(): void
    {
        $quote = $this->makeQuote(['coupon_code' => 'OLD', 'subtotal' => 50], [1 => $this->makeItem([])], 0.0, static function (Quote $q) {
            if ($q->getCouponCode() === 'BAD') {
                $q->setCouponCode(null);
            }
        });

        try {
            $this->service()->applyCoupon(4, ' BAD ');
            $this->fail('Expected exception');
        } catch (LocalizedException $e) {
            $this->assertSame('The coupon code "BAD" is not valid.', $e->getMessage());
        }
        $this->assertSame('OLD', $quote->getCouponCode());
    }

    public function testCouponSaveFailuresAreWrapped(): void
    {
        $this->makeQuote(['subtotal' => 50], [1 => $this->makeItem([])]);
        $this->quoteRepository->method('save')->willReturnOnConsecutiveCalls(
            $this->throwException(new LocalizedException(__('Rule expired'))),
            $this->throwException(new \RuntimeException('db'))
        );
        $service = $this->service();

        try {
            $service->applyCoupon(4, 'X');
            $this->fail('Expected exception');
        } catch (LocalizedException $e) {
            $this->assertSame('The coupon code could not be applied: Rule expired', $e->getMessage());
        }

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The coupon code could not be applied. Verify the coupon code and try again.');
        $service->applyCoupon(4, 'X');
    }

    public function testCouponOnZeroSubtotalSkipsCapCheck(): void
    {
        $this->makeQuote(['subtotal' => 0], [1 => $this->makeItem([])], 5.0);
        $this->authService->expects($this->never())->method('getMaxDiscountPercent');

        $this->assertSame(['quote_id' => 4], $this->service()->applyCoupon(4, 'FREE'));
    }

    public function testRemoveItemDiscountWithoutBaselineDoesNothing(): void
    {
        $item = $this->makeItem(['additional_data' => '{"pos_note":"x"}', 'custom_price' => 5]);
        $this->makeQuote([], [8 => $item]);
        $this->quoteRepository->expects($this->never())->method('save');

        $this->assertSame(['quote_id' => 4], $this->service()->removeItemDiscount(4, 8));
        $this->assertSame(5, $item->getCustomPrice());
    }

    public function testRemoveItemDiscountRestoresCustomBaselinePrice(): void
    {
        $item = $this->makeItem([
            'custom_price' => 8,
            'additional_data' => json_encode([
                'panth_pos_original_price' => 10, 'panth_pos_baseline_is_custom' => true,
                'panth_pos_item_discount' => ['type' => 'fixed'], 'pos_note' => 'keep',
            ]),
        ]);
        $this->makeQuote([], [8 => $item]);
        $this->quoteRepository->expects($this->once())->method('save');

        $this->service()->removeItemDiscount(4, 8);

        $this->assertSame(10.0, $item->getCustomPrice());
        $this->assertSame(10.0, $item->getOriginalCustomPrice());
        $this->assertSame(['pos_note' => 'keep'], json_decode($item->getAdditionalData(), true));
    }

    public function testDiscountOnChildItemIsRejected(): void
    {
        $this->makeQuote([], [8 => $this->makeItem(['parent_item_id' => 7])]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Discounts must be applied to the parent line, not a child item.');
        $this->service()->removeItemDiscount(4, 8);
    }

    public function testDiscountOnDeletedItemIsRejected(): void
    {
        $item = $this->makeItem([]);
        $item->isDeleted(true);
        $this->makeQuote([], [8 => $item]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Cart item 8 was not found.');
        $this->service()->applyItemDiscount(4, 8, 'percent', 5);
    }
}
