<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Model\NameDecoder;

class CartService
{
    private const QUOTE_NOTE_KEY = 'panth_pos_note';

    private const QUOTE_POS_DISCOUNT_TYPE = 'panth_pos_discount_type';
    private const QUOTE_POS_DISCOUNT_VALUE = 'panth_pos_discount_value';

    public const QUOTE_POS_REGISTER_ID = 'panth_pos_register_id';

    private const ITEM_NOTE_KEY = 'pos_note';
    private const ITEM_IS_CUSTOM_KEY = 'pos_custom';
    private const ITEM_CUSTOM_NAME_KEY = 'pos_custom_name';
    private const ITEM_TAX_CLASS_KEY = 'pos_tax_class_id';

    private array $groupLabels = [];

    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly QuoteFactory $quoteFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly SessionManagerInterface $sessionManager,
        private readonly SessionRepositoryInterface $posSessionRepository,
        private readonly RegisterRepositoryInterface $registerRepository,
        private readonly AuthService $authService,
        private readonly Config $config,
        private readonly ImageHelper $imageHelper,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly QuotePreparer $quotePreparer,
        private readonly GroupRepositoryInterface $groupRepository
    ) {
    }

    public function create(): int
    {
        $register = $this->resolveRegister();
        $storeId = $register !== null
            ? $register->getStoreId()
            : (int)$this->storeManager->getStore()->getId();
        $quote = $this->quoteFactory->create();
        $quote->setStoreId($storeId);
        $quote->setIsActive(true);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerGroupId($this->config->getDefaultCustomerGroupId($storeId));
        $quote->setCustomerEmail($this->config->getGuestEmail($storeId));

        $quote->setData(self::QUOTE_POS_REGISTER_ID, $register !== null ? (int)$register->getRegisterId() : 0);
        $this->cartRepository->save($quote);

        return (int)$quote->getId();
    }

    public function get(int $quoteId): array
    {
        return $this->buildCartPayload($this->getQuote($quoteId));
    }

    public function addProduct(int $quoteId, int|string $productIdOrSku, float $qty, array $options = []): array
    {
        if ($qty <= 0) {
            throw new LocalizedException(__('Quantity must be greater than zero.'));
        }
        $quote = $this->getQuote($quoteId);
        $product = $this->resolveProduct($productIdOrSku, (int)$quote->getStoreId());

        $buyRequest = $this->dataObjectFactory->create(
            ['data' => $this->buildBuyRequestData($qty, $options)]
        );

        $result = $quote->addProduct($product, $buyRequest);
        if (is_string($result)) {
            throw new LocalizedException(__($result));
        }

        return $this->buildCartPayload($this->collectAndSave($quote));
    }

    private function buildBuyRequestData(float $qty, array $options): array
    {
        $data = ['qty' => $qty];

        if (isset($options['super_attribute']) && is_array($options['super_attribute'])) {
            $superAttribute = [];
            foreach ($options['super_attribute'] as $attributeId => $valueId) {
                if ((int)$attributeId > 0 && is_numeric($valueId) && (int)$valueId > 0) {
                    $superAttribute[(int)$attributeId] = (int)$valueId;
                }
            }
            if ($superAttribute !== []) {
                $data['super_attribute'] = $superAttribute;
            }
        }

        if (isset($options['super_group']) && is_array($options['super_group'])) {
            $superGroup = [];
            foreach ($options['super_group'] as $childId => $childQty) {
                if ((int)$childId > 0 && is_numeric($childQty) && (float)$childQty >= 0) {
                    $superGroup[(int)$childId] = (float)$childQty;
                }
            }
            if ($superGroup !== []) {
                $data['super_group'] = $superGroup;
            }
        }

        if (isset($options['bundle_option']) && is_array($options['bundle_option'])) {
            $bundleOption = [];
            foreach ($options['bundle_option'] as $optionId => $selection) {
                if ((int)$optionId <= 0) {
                    continue;
                }
                if (is_array($selection)) {
                    $selectionIds = [];
                    foreach ($selection as $selectionId) {
                        if (is_numeric($selectionId) && (int)$selectionId > 0) {
                            $selectionIds[] = (int)$selectionId;
                        }
                    }
                    if ($selectionIds !== []) {
                        $bundleOption[(int)$optionId] = $selectionIds;
                    }
                } elseif (is_numeric($selection) && (int)$selection > 0) {
                    $bundleOption[(int)$optionId] = (int)$selection;
                }
            }
            if ($bundleOption !== []) {
                $data['bundle_option'] = $bundleOption;
            }
        }

        if (isset($options['bundle_option_qty']) && is_array($options['bundle_option_qty'])) {
            $bundleOptionQty = [];
            foreach ($options['bundle_option_qty'] as $optionId => $optionQty) {
                if ((int)$optionId > 0 && is_numeric($optionQty) && (float)$optionQty > 0) {
                    $bundleOptionQty[(int)$optionId] = (float)$optionQty;
                }
            }
            if ($bundleOptionQty !== []) {
                $data['bundle_option_qty'] = $bundleOptionQty;
            }
        }

        $customOptions = $options['custom_options'] ?? $options['options'] ?? null;
        if (is_array($customOptions)) {
            $optionValues = [];
            foreach ($customOptions as $optionId => $value) {
                if ((int)$optionId > 0 && (is_scalar($value) || is_array($value))) {
                    $optionValues[(int)$optionId] = $value;
                }
            }
            if ($optionValues !== []) {
                $data['options'] = $optionValues;
            }
        }

        return $data;
    }

    public function updateItem(int $quoteId, int $itemId, ?float $qty, ?float $price, ?string $note): array
    {
        $quote = $this->getQuote($quoteId);
        $item = $quote->getItemById($itemId);
        if (!$item) {
            throw new NoSuchEntityException(__('Cart item "%1" was not found.', $itemId));
        }

        if ($price !== null) {
            $this->authService->requirePermission('can_price_override');
            if ($price < 0) {
                throw new LocalizedException(__('Price cannot be negative.'));
            }
            $item->setCustomPrice($price);
            $item->setOriginalCustomPrice($price);
            if ($item->getProduct()) {
                $item->getProduct()->setIsSuperMode(true);
            }
        }

        if ($note !== null) {
            $this->setItemAdditionalData($item, [self::ITEM_NOTE_KEY => $note === '' ? null : $note]);
        }

        if ($qty !== null) {
            if ($qty <= 0) {
                $quote->removeItem($itemId);
            } else {
                $item->setQty($qty);
            }
        }

        return $this->buildCartPayload($this->collectAndSave($quote));
    }

    public function removeItem(int $quoteId, int $itemId): array
    {
        $quote = $this->getQuote($quoteId);
        $quote->removeItem($itemId);

        return $this->buildCartPayload($this->collectAndSave($quote));
    }

    public function clear(int $quoteId): array
    {
        $quote = $this->getQuote($quoteId);
        $quote->removeAllItems();

        return $this->buildCartPayload($this->collectAndSave($quote));
    }

    public function setCustomer(int $quoteId, ?int $customerId): array
    {
        $quote = $this->getQuote($quoteId);
        $storeId = (int)$quote->getStoreId();

        if ($customerId === null) {
            $quote->setCustomerId(null);
            $quote->setCustomerIsGuest(true);
            $quote->setCustomerEmail($this->config->getGuestEmail($storeId));
            $quote->setCustomerFirstname(null);
            $quote->setCustomerLastname(null);
            $quote->setCustomerGroupId($this->config->getDefaultCustomerGroupId($storeId));
        } else {
            $customer = $this->customerRepository->getById($customerId);
            $quote->setCustomer($customer);
            $quote->setCustomerIsGuest(false);
            $quote->setCustomerEmail((string)$customer->getEmail());
            $quote->setCustomerFirstname((string)$customer->getFirstname());
            $quote->setCustomerLastname((string)$customer->getLastname());
            $quote->setCustomerGroupId((int)$customer->getGroupId());
        }

        return $this->buildCartPayload($this->collectAndSave($quote));
    }

    public function addCustomItem(int $quoteId, string $name, float $price, float $qty, ?int $taxClassId): array
    {
        $this->authService->requirePermission('can_custom_product');

        $name = trim($name);
        if ($name === '') {
            throw new LocalizedException(__('Custom item name is required.'));
        }
        if ($price < 0) {
            throw new LocalizedException(__('Price cannot be negative.'));
        }
        if ($qty <= 0) {
            throw new LocalizedException(__('Quantity must be greater than zero.'));
        }

        $quote = $this->getQuote($quoteId);
        $storeId = (int)$quote->getStoreId();
        $sku = $this->config->getCustomProductSku($storeId);
        try {
            $product = $this->productRepository->get($sku, false, $storeId, true);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(
                __('The POS custom sale placeholder product "%1" does not exist.', $sku)
            );
        }

        $taxClassId = $taxClassId ?? $this->config->getCustomProductDefaultTaxClassId($storeId);
        $product->setTaxClassId($taxClassId);

        $buyRequest = $this->dataObjectFactory->create(
            ['data' => ['qty' => $qty, 'pos_custom_uid' => uniqid('pos_', true)]]
        );
        $result = $quote->addProduct($product, $buyRequest);
        if (is_string($result)) {
            throw new LocalizedException(__($result));
        }

        $result->setName($name);
        $result->setCustomPrice($price);
        $result->setOriginalCustomPrice($price);
        if ($result->getProduct()) {
            $result->getProduct()->setIsSuperMode(true);
        }
        $this->setItemAdditionalData($result, [
            self::ITEM_IS_CUSTOM_KEY => true,
            self::ITEM_CUSTOM_NAME_KEY => $name,
            self::ITEM_TAX_CLASS_KEY => $taxClassId,
        ]);

        return $this->buildCartPayload($this->collectAndSave($quote));
    }

    public function setNote(int $quoteId, string $note): array
    {
        $quote = $this->getQuote($quoteId);
        $quote->setData(self::QUOTE_NOTE_KEY, trim($note));
        $this->cartRepository->save($quote);

        return $this->buildCartPayload($quote);
    }

    public function buildCartPayload(Quote $quote): array
    {
        $storeId = (int)$quote->getStoreId();
        $customSku = $this->config->getCustomProductSku($storeId);

        $items = [];
        $discount = 0.0;
        $tax = 0.0;
        foreach ($quote->getAllVisibleItems() as $item) {
            $data = $this->getItemAdditionalData($item);
            $isCustom = !empty($data[self::ITEM_IS_CUSTOM_KEY]) || (string)$item->getSku() === $customSku;
            $customName = $data[self::ITEM_CUSTOM_NAME_KEY] ?? null;
            $note = $data[self::ITEM_NOTE_KEY] ?? null;
            $itemDiscount = (float)$item->getDiscountAmount();
            $itemTax = (float)$item->getTaxAmount();
            $discount += $itemDiscount;
            $tax += $itemTax;

            $items[] = [
                'item_id' => (int)$item->getId(),
                'product_id' => (int)$item->getProductId(),
                'sku' => (string)$item->getSku(),
                'name' => is_string($customName) && $customName !== '' ? $customName : NameDecoder::decode($item->getName()),
                'qty' => (float)$item->getQty(),
                'price' => (float)$item->getCalculationPrice(),
                'original_price' => (float)($item->getOriginalPrice() ?: $item->getPrice()),
                'row_total' => (float)$item->getRowTotal(),
                'discount_amount' => $itemDiscount,
                'tax_amount' => $itemTax,
                'note' => is_string($note) && $note !== '' ? $note : null,
                'is_custom' => $isCustom,
                'image' => $isCustom ? null : $this->getItemImageUrl($item),
                'type' => (string)$item->getProductType(),

                'options' => $isCustom ? [] : $this->getItemOptionLabels($item),
            ];
        }

        $subtotal = (float)$quote->getSubtotal();
        [$posDiscount, $posDiscountAmount] = $this->getPosDiscount($quote, $subtotal);

        $customer = null;
        if ($quote->getCustomerId()) {
            $groupId = (int)$quote->getCustomerGroupId();
            $customer = [
                'id' => (int)$quote->getCustomerId(),
                'name' => trim((string)$quote->getCustomerFirstname() . ' ' . (string)$quote->getCustomerLastname()),
                'email' => (string)$quote->getCustomerEmail(),
                'group' => $this->getGroupLabel($groupId),
                'group_id' => $groupId,
            ];
        }

        $quoteNote = trim((string)($quote->getData(self::QUOTE_NOTE_KEY) ?? ''));

        return [
            'quote_id' => (int)$quote->getId(),
            'items' => $items,
            'totals' => [
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'pos_discount' => round($posDiscountAmount, 2),
                'tax' => round($tax, 2),
                'grand_total' => round((float)$quote->getGrandTotal(), 2),
                'items_qty' => (float)$quote->getItemsQty(),
            ],
            'customer' => $customer,
            'coupon_code' => $quote->getCouponCode() ? (string)$quote->getCouponCode() : null,
            'pos_discount' => $posDiscount,
            'note' => $quoteNote !== '' ? $quoteNote : null,
        ];
    }

    private function getQuote(int $quoteId): Quote
    {
        $quote = $this->cartRepository->get($quoteId);
        if (!$quote instanceof Quote) {
            throw new NoSuchEntityException(__('Cart "%1" was not found.', $quoteId));
        }
        $this->assertPosQuote($quote);

        return $quote;
    }

    public function assertPosQuote(Quote $quote): void
    {
        $marker = $quote->getData(self::QUOTE_POS_REGISTER_ID);
        if ($marker === null || $marker === '') {
            throw new NoSuchEntityException(__('Cart "%1" was not found.', $quote->getId()));
        }
        $quoteRegisterId = (int)$marker;
        if ($quoteRegisterId > 0) {
            $register = $this->resolveRegister();
            if ($register !== null && (int)$register->getRegisterId() !== $quoteRegisterId) {
                throw new NoSuchEntityException(__('Cart "%1" was not found.', $quote->getId()));
            }
        }
    }

    public function prepareQuote(Quote $quote): void
    {
        $this->quotePreparer->prepare($quote, false);
    }

    private function collectAndSave(Quote $quote): Quote
    {
        $this->applyLineOverrides($quote);
        $this->prepareQuote($quote);
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        return $quote;
    }

    private function applyLineOverrides(Quote $quote): void
    {
        foreach ($quote->getAllItems() as $item) {
            $data = $this->getItemAdditionalData($item);
            $product = $item->getProduct();
            if ($product && isset($data[self::ITEM_TAX_CLASS_KEY])) {
                $product->setTaxClassId((int)$data[self::ITEM_TAX_CLASS_KEY]);
            }
            $customName = $data[self::ITEM_CUSTOM_NAME_KEY] ?? null;
            if (is_string($customName) && $customName !== '') {
                $item->setName($customName);
            }
            if ($product && $item->getCustomPrice() !== null) {
                $product->setIsSuperMode(true);
            }
        }
    }

    private function resolveProduct(int|string $productIdOrSku, int $storeId): ProductInterface
    {
        if (is_int($productIdOrSku)) {
            return $this->productRepository->getById($productIdOrSku, false, $storeId);
        }
        try {
            return $this->productRepository->get($productIdOrSku, false, $storeId);
        } catch (NoSuchEntityException $e) {
            if (is_numeric($productIdOrSku)) {
                return $this->productRepository->getById((int)$productIdOrSku, false, $storeId);
            }
            throw $e;
        }
    }

    private function resolveRegister(): ?\Panth\MagePos\Api\Data\RegisterInterface
    {
        $posSessionId = (int)$this->sessionManager->getData('panth_pos_session_id');
        if ($posSessionId > 0) {
            try {
                $posSession = $this->posSessionRepository->getById($posSessionId);

                return $this->registerRepository->getById($posSession->getRegisterId());
            } catch (NoSuchEntityException $e) {
            }
        }

        return null;
    }

    private function getPosDiscount(Quote $quote, float $subtotal): array
    {
        $type = (string)($quote->getData(self::QUOTE_POS_DISCOUNT_TYPE) ?? '');
        $rawValue = $quote->getData(self::QUOTE_POS_DISCOUNT_VALUE);
        if ($type === '' || $rawValue === null || $rawValue === '') {
            return [null, 0.0];
        }
        $value = (float)$rawValue;
        $amount = $type === 'percent'
            ? $subtotal * $value / 100
            : min($value, max($subtotal, 0.0));

        return [['type' => $type, 'value' => $value], round($amount, 2)];
    }

    private function getItemOptionLabels(Item $item): array
    {
        try {
            $product = $item->getProduct();
            if (!$product) {
                return [];
            }
            $orderOptions = $product->getTypeInstance()->getOrderOptions($product);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($orderOptions)) {
            return [];
        }

        $rows = [];

        foreach ($orderOptions['attributes_info'] ?? [] as $info) {
            if (is_array($info) && isset($info['label'], $info['value'])) {
                $rows[] = [
                    'label' => (string)$info['label'],
                    'value' => (string)$info['value'],
                ];
            }
        }

        foreach ($orderOptions['bundle_options'] ?? [] as $bundleOption) {
            if (!is_array($bundleOption)) {
                continue;
            }
            $parts = [];
            foreach ($bundleOption['value'] ?? [] as $selection) {
                if (!is_array($selection)) {
                    continue;
                }
                $title = (string)($selection['title'] ?? '');
                if ($title === '') {
                    continue;
                }
                $selectionQty = (float)($selection['qty'] ?? 1);
                $parts[] = ($selectionQty > 0 ? $this->formatQtyLabel($selectionQty) . ' × ' : '') . $title;
            }
            if ($parts !== []) {
                $rows[] = [
                    'label' => (string)($bundleOption['label'] ?? ''),
                    'value' => implode(', ', $parts),
                ];
            }
        }

        foreach ($orderOptions['options'] ?? [] as $customOption) {
            if (!is_array($customOption)) {
                continue;
            }
            $value = $customOption['print_value'] ?? $customOption['value'] ?? '';
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $row = [
                'label' => (string)($customOption['label'] ?? ''),
                'value' => $value,
            ];
            $delta = $this->getCustomOptionDelta($product, $customOption);
            if ($delta !== null && abs($delta) > 0.004) {
                $row['price'] = round($delta, 2);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function getCustomOptionDelta($product, array $customOption): ?float
    {
        try {
            $option = $product->getOptionById((int)($customOption['option_id'] ?? 0));
            if ($option === null) {
                return null;
            }
            $values = $option->getValues();
            if (is_array($values) && $values !== []) {
                $chosenIds = array_filter(
                    array_map('trim', explode(',', (string)($customOption['option_value'] ?? ''))),
                    static fn (string $id): bool => $id !== '' && is_numeric($id)
                );
                if ($chosenIds === []) {
                    return null;
                }
                $delta = 0.0;
                foreach ($chosenIds as $valueId) {
                    $value = $option->getValueById((int)$valueId);
                    if ($value !== null) {
                        $delta += (float)$value->getPrice(true);
                    }
                }

                return $delta;
            }

            return (float)$option->getPrice(true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function formatQtyLabel(float $qty): string
    {
        $label = rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');

        return $label === '' ? '0' : $label;
    }

    private function getItemImageUrl(Item $item): ?string
    {
        try {
            $product = $item->getProduct();
            if (!$product) {
                return null;
            }

            return $this->imageHelper->init($product, 'product_thumbnail_image')->resize(200, 200)->getUrl();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getItemAdditionalData($item): array
    {
        $raw = $item->getAdditionalData();
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function setItemAdditionalData($item, array $values): void
    {
        $data = array_merge($this->getItemAdditionalData($item), $values);
        $data = array_filter($data, static fn ($value) => $value !== null);
        $item->setAdditionalData($data === [] ? null : json_encode($data));
    }

    private function getGroupLabel(int $groupId): string
    {
        if (isset($this->groupLabels[$groupId])) {
            return $this->groupLabels[$groupId];
        }
        try {
            $label = (string)$this->groupRepository->getById($groupId)->getCode();
        } catch (LocalizedException $e) {
            $label = '';
        }
        if ($label === '') {
            $label = (string)$groupId;
        }
        $this->groupLabels[$groupId] = $label;

        return $label;
    }
}
