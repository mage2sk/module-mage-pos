<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;

class DiscountService
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';

    public const QUOTE_DISCOUNT_TYPE = 'panth_pos_discount_type';
    public const QUOTE_DISCOUNT_VALUE = 'panth_pos_discount_value';

    private const ITEM_KEY_ORIGINAL_PRICE = 'panth_pos_original_price';
    private const ITEM_KEY_BASELINE_IS_CUSTOM = 'panth_pos_baseline_is_custom';
    private const ITEM_KEY_DISCOUNT = 'panth_pos_item_discount';

    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly AuthService $authService,
        private readonly CartService $cartService
    ) {
    }

    public function applyCartDiscount(int $quoteId, string $type, float $value): array
    {
        $type = $this->normalizeType($type);
        $this->validateValue($type, $value);

        $quote = $this->loadQuote($quoteId);
        $subtotal = $this->getSubtotal($quote);
        if ($subtotal <= 0) {
            throw new LocalizedException(__('A discount cannot be applied to an empty cart.'));
        }

        $postRuleSubtotal = $this->getPostRuleSubtotal($quote, $subtotal);
        if ($postRuleSubtotal <= 0) {
            throw new LocalizedException(
                __('Native promotions already discount this cart to zero; no further discount can be applied.')
            );
        }

        if ($type === self::TYPE_FIXED && $value > $postRuleSubtotal) {
            throw new LocalizedException(__('A fixed discount cannot exceed the cart subtotal.'));
        }

        $effectivePercent = $type === self::TYPE_PERCENT ? $value : ($value / $postRuleSubtotal) * 100;
        $this->assertWithinCap($effectivePercent);

        $quote->setData(self::QUOTE_DISCOUNT_TYPE, $type);
        $quote->setData(self::QUOTE_DISCOUNT_VALUE, round($value, 4));
        $this->recollect($quote);
        $this->quoteRepository->save($quote);

        return $this->cartService->get($quoteId);
    }

    public function removeCartDiscount(int $quoteId): array
    {
        $quote = $this->loadQuote($quoteId);
        $quote->setData(self::QUOTE_DISCOUNT_TYPE, null);
        $quote->setData(self::QUOTE_DISCOUNT_VALUE, null);
        $this->recollect($quote);
        $this->quoteRepository->save($quote);

        return $this->cartService->get($quoteId);
    }

    public function applyItemDiscount(int $quoteId, int $itemId, string $type, float $value): array
    {
        $type = $this->normalizeType($type);
        $this->validateValue($type, $value);

        $quote = $this->loadQuote($quoteId);
        $item = $this->getQuoteItem($quote, $itemId);

        $qty = (float)$item->getQty();
        if ($qty <= 0) {
            throw new LocalizedException(__('Cart item %1 has no quantity.', $itemId));
        }

        $additional = $this->readAdditionalData($item);
        $baseline = $this->resolveBaselinePrice($item, $additional);
        $rowBase = $baseline * $qty;
        if ($rowBase <= 0) {
            throw new LocalizedException(__('A discount cannot be applied to a zero-priced line.'));
        }

        if ($type === self::TYPE_PERCENT) {
            $effectivePercent = $value;
            $discountPerUnit = $baseline * $value / 100;
        } else {
            if ($value > $rowBase) {
                throw new LocalizedException(__('A fixed discount cannot exceed the line total.'));
            }
            $effectivePercent = ($value / $rowBase) * 100;
            $discountPerUnit = $value / $qty;
        }
        $this->assertWithinCap($effectivePercent);

        $newPrice = round(max(0.0, $baseline - $discountPerUnit), 4);

        $item->setCustomPrice($newPrice);
        $item->setOriginalCustomPrice($newPrice);

        $additional[self::ITEM_KEY_ORIGINAL_PRICE] = $baseline;
        $additional[self::ITEM_KEY_DISCOUNT] = ['type' => $type, 'value' => round($value, 4)];
        $this->writeAdditionalData($item, $additional);

        $this->recollect($quote);
        $this->quoteRepository->save($quote);

        return $this->cartService->get($quoteId);
    }

    public function removeItemDiscount(int $quoteId, int $itemId): array
    {
        $quote = $this->loadQuote($quoteId);
        $item = $this->getQuoteItem($quote, $itemId);

        $additional = $this->readAdditionalData($item);
        if (array_key_exists(self::ITEM_KEY_ORIGINAL_PRICE, $additional)) {
            $baseline = (float)$additional[self::ITEM_KEY_ORIGINAL_PRICE];
            $baselineIsCustom = (bool)($additional[self::ITEM_KEY_BASELINE_IS_CUSTOM] ?? false);
            if ($baselineIsCustom) {
                $item->setCustomPrice($baseline);
                $item->setOriginalCustomPrice($baseline);
            } else {
                $item->setCustomPrice(null);
                $item->setOriginalCustomPrice(null);
            }
            unset(
                $additional[self::ITEM_KEY_ORIGINAL_PRICE],
                $additional[self::ITEM_KEY_BASELINE_IS_CUSTOM],
                $additional[self::ITEM_KEY_DISCOUNT]
            );
            $this->writeAdditionalData($item, $additional);

            $this->recollect($quote);
            $this->quoteRepository->save($quote);
        }

        return $this->cartService->get($quoteId);
    }

    public function applyCoupon(int $quoteId, string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new LocalizedException(__('A coupon code is required.'));
        }

        $quote = $this->loadQuote($quoteId);
        if (!$quote->getItemsCount()) {
            throw new LocalizedException(__('A coupon cannot be applied to an empty cart.'));
        }
        $previousCode = (string)$quote->getCouponCode();

        $quote->getShippingAddress()->setCollectShippingRates(true);
        try {
            $quote->setCouponCode($code);
            $this->recollect($quote);
            $this->quoteRepository->save($quote);
        } catch (LocalizedException $e) {
            throw new LocalizedException(__('The coupon code could not be applied: %1', $e->getMessage()), $e);
        } catch (\Exception $e) {
            throw new LocalizedException(
                __('The coupon code could not be applied. Verify the coupon code and try again.'),
                $e
            );
        }

        if ((string)$quote->getCouponCode() !== $code) {
            $this->restoreCoupon($quote, $previousCode);
            throw new LocalizedException(__('The coupon code "%1" is not valid.', $code));
        }

        $subtotal = $this->getSubtotal($quote);
        if ($subtotal > 0) {
            $couponDiscount = $this->getSalesRuleDiscountAmount($quote);
            $effectivePercent = ($couponDiscount / $subtotal) * 100;
            $max = $this->authService->getMaxDiscountPercent();
            if ($effectivePercent - $max > 0.0001) {
                $this->restoreCoupon($quote, $previousCode);
                throw new LocalizedException(
                    __(
                        'Coupon "%1" gives a %2% discount which exceeds your allowed maximum of %3%.',
                        $code,
                        round($effectivePercent, 2),
                        round($max, 2)
                    )
                );
            }
        }

        return $this->cartService->get($quoteId);
    }

    public function removeCoupon(int $quoteId): array
    {
        $quote = $this->loadQuote($quoteId);
        $quote->getShippingAddress()->setCollectShippingRates(true);
        try {
            $quote->setCouponCode('');
            $this->recollect($quote);
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            throw new LocalizedException(
                __('The coupon code could not be removed. Please try again.'),
                $e
            );
        }

        return $this->cartService->get($quoteId);
    }

    private function loadQuote(int $quoteId): Quote
    {
        try {
            $quote = $this->quoteRepository->getActive($quoteId);
            if ($quote instanceof Quote) {
                $this->cartService->assertPosQuote($quote);
            }
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Cart %1 no longer exists.', $quoteId), $e);
        }
        if (!$quote instanceof Quote) {
            throw new LocalizedException(__('Unable to load cart %1.', $quoteId));
        }

        return $quote;
    }

    private function getQuoteItem(Quote $quote, int $itemId): Item
    {
        $item = $quote->getItemById($itemId);
        if (!$item instanceof Item || $item->isDeleted()) {
            throw new LocalizedException(__('Cart item %1 was not found.', $itemId));
        }
        if ($item->getParentItemId()) {
            throw new LocalizedException(__('Discounts must be applied to the parent line, not a child item.'));
        }

        return $item;
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, [self::TYPE_PERCENT, self::TYPE_FIXED], true)) {
            throw new LocalizedException(__('Discount type must be "percent" or "fixed".'));
        }

        return $type;
    }

    private function validateValue(string $type, float $value): void
    {
        if ($value <= 0) {
            throw new LocalizedException(__('The discount value must be greater than zero.'));
        }
        if ($type === self::TYPE_PERCENT && $value > 100) {
            throw new LocalizedException(__('A percentage discount cannot exceed 100%.'));
        }
    }

    private function assertWithinCap(float $effectivePercent): void
    {
        $max = $this->authService->getMaxDiscountPercent();
        if ($effectivePercent - $max > 0.0001) {
            throw new LocalizedException(
                __(
                    'A %1% discount exceeds your allowed maximum of %2%.',
                    round($effectivePercent, 2),
                    round($max, 2)
                )
            );
        }
    }

    private function getSubtotal(Quote $quote): float
    {
        $subtotal = (float)$quote->getSubtotal();
        if ($subtotal <= 0 && count($quote->getAllVisibleItems()) > 0) {
            $this->recollect($quote);
            $subtotal = (float)$quote->getSubtotal();
        }

        return $subtotal;
    }

    private function getPostRuleSubtotal(Quote $quote, float $subtotal): float
    {
        $ruleDiscount = $this->getSalesRuleDiscountAmount($quote);

        return max(0.0, $subtotal - $ruleDiscount);
    }

    private function getSalesRuleDiscountAmount(Quote $quote): float
    {
        $discount = 0.0;
        foreach ($quote->getAllAddresses() as $address) {
            $discount += abs((float)$address->getDiscountAmount());
        }

        return $discount;
    }

    private function restoreCoupon(Quote $quote, string $previousCode): void
    {
        try {
            $quote->setCouponCode($previousCode);
            $this->recollect($quote);
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            return;
        }
    }

    private function recollect(Quote $quote): void
    {
        $this->cartService->prepareQuote($quote);
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
    }

    private function resolveBaselinePrice(Item $item, array &$additional): float
    {
        if (array_key_exists(self::ITEM_KEY_ORIGINAL_PRICE, $additional)) {
            return (float)$additional[self::ITEM_KEY_ORIGINAL_PRICE];
        }

        $customPrice = $item->getCustomPrice();
        if ($customPrice !== null && $customPrice !== '' && (float)$customPrice > 0) {
            $additional[self::ITEM_KEY_BASELINE_IS_CUSTOM] = true;

            return (float)$customPrice;
        }

        $additional[self::ITEM_KEY_BASELINE_IS_CUSTOM] = false;
        $price = (float)$item->getPrice();
        if ($price <= 0) {
            $product = $item->getProduct();
            if ($product) {
                $price = (float)$product->getFinalPrice($item->getQty());
            }
        }

        return $price;
    }

    private function readAdditionalData(Item $item): array
    {
        $raw = (string)$item->getAdditionalData();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeAdditionalData(Item $item, array $additional): void
    {
        $item->setAdditionalData($additional === [] ? null : json_encode($additional));
    }
}
