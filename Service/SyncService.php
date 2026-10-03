<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Panth\MagePos\Api\Data\PosOrderInterface;
use Panth\MagePos\Api\PosOrderRepositoryInterface;

class SyncService
{
    public const STATUS_CREATED = 'created';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_ERROR = 'error';

    public function __construct(
        private readonly AuthService $authService,
        private readonly CartService $cartService,
        private readonly DiscountService $discountService,
        private readonly CheckoutService $checkoutService,
        private readonly PosOrderRepositoryInterface $posOrderRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function pushOrders(array $queued): array
    {
        $this->authService->requireUser();

        $entries = array_values($queued);

        $batchUuids = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $uuid = isset($entry['client_uuid']) ? trim((string)$entry['client_uuid']) : '';
                if ($uuid !== '') {
                    $batchUuids[$uuid] = true;
                }
            }
        }
        $syncedOrderIds = $this->findSyncedOrderIds(array_keys($batchUuids));

        $results = [];
        $incrementIdMemo = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $results['invalid_' . $index] = [
                    'status' => self::STATUS_ERROR,
                    'message' => (string)__('Queued order entry is not an object.'),
                ];
                continue;
            }

            $clientUuid = isset($entry['client_uuid']) ? trim((string)$entry['client_uuid']) : '';
            if ($clientUuid === '') {
                $results['invalid_' . $index] = [
                    'status' => self::STATUS_ERROR,
                    'message' => (string)__('Queued order is missing its client_uuid.'),
                ];
                continue;
            }

            if (isset($results[$clientUuid])) {
                $result = ['status' => self::STATUS_DUPLICATE];
                if (isset($results[$clientUuid]['increment_id'])) {
                    $result['increment_id'] = $results[$clientUuid]['increment_id'];
                }
                $results[$clientUuid] = $result;
                continue;
            }

            if (isset($syncedOrderIds[$clientUuid])) {
                $orderId = $syncedOrderIds[$clientUuid];
                if (!array_key_exists($orderId, $incrementIdMemo)) {
                    $incrementIdMemo[$orderId] = $this->resolveIncrementId($orderId);
                }
                $result = ['status' => self::STATUS_DUPLICATE];
                if ($incrementIdMemo[$orderId] !== null && $incrementIdMemo[$orderId] !== '') {
                    $result['increment_id'] = $incrementIdMemo[$orderId];
                }
                $results[$clientUuid] = $result;
                continue;
            }

            try {
                $placed = $this->replayOrder($clientUuid, $entry);
                $result = ['status' => self::STATUS_CREATED];
                if (isset($placed['increment_id'])) {
                    $result['increment_id'] = (string)$placed['increment_id'];
                }
                $results[$clientUuid] = $result;
            } catch (LocalizedException $e) {
                $results[$clientUuid] = [
                    'status' => self::STATUS_ERROR,
                    'message' => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $results[$clientUuid] = [
                    'status' => self::STATUS_ERROR,
                    'message' => (string)__('Could not sync the order: %1', $e->getMessage()),
                ];
            }
        }

        return $results;
    }

    private function replayOrder(string $clientUuid, array $entry): array
    {
        $cart = is_array($entry['cart'] ?? null) ? $entry['cart'] : [];
        $items = is_array($cart['items'] ?? null) ? array_values($cart['items']) : [];
        if ($items === []) {
            throw new LocalizedException(__('Queued order has no items.'));
        }

        $payments = is_array($entry['payments'] ?? null) ? array_values($entry['payments']) : [];
        if ($payments === []) {
            throw new LocalizedException(__('Queued order has no payments.'));
        }

        $quoteId = $this->cartService->create();

        $this->addItems($quoteId, $items);

        $customerId = isset($cart['customer_id']) && is_numeric($cart['customer_id'])
            ? (int)$cart['customer_id']
            : 0;
        if ($customerId > 0) {
            $this->cartService->setCustomer($quoteId, $customerId);
        }

        $note = isset($cart['note']) ? trim((string)$cart['note']) : '';
        if ($note !== '') {
            $this->cartService->setNote($quoteId, $note);
        }

        $discounts = is_array($cart['discounts'] ?? null) ? $cart['discounts'] : [];
        $this->applyDiscounts($quoteId, $discounts);

        $opts = [
            'is_offline_sync' => true,
            'client_uuid' => $clientUuid,
        ];
        if ($note !== '') {
            $opts['note'] = $note;
        }
        if (isset($entry['created_at']) && is_string($entry['created_at']) && $entry['created_at'] !== '') {
            $opts['created_at'] = $entry['created_at'];
        }

        return $this->checkoutService->placeOrder($quoteId, $payments, $opts);
    }

    private function addItems(int $quoteId, array $items): void
    {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new LocalizedException(__('Queued order item %1 is invalid.', $index + 1));
            }

            $qty = isset($item['qty']) && is_numeric($item['qty']) ? (float)$item['qty'] : 1.0;
            if ($qty <= 0) {
                throw new LocalizedException(__('Queued order item %1 has an invalid quantity.', $index + 1));
            }

            if (!empty($item['is_custom'])) {
                $name = isset($item['name']) ? trim((string)$item['name']) : '';
                if ($name === '' || !isset($item['price']) || !is_numeric($item['price'])) {
                    throw new LocalizedException(__('Queued custom item %1 needs a name and price.', $index + 1));
                }
                $taxClassId = isset($item['tax_class_id']) && is_numeric($item['tax_class_id'])
                    ? (int)$item['tax_class_id']
                    : null;
                $this->cartService->addCustomItem($quoteId, $name, (float)$item['price'], $qty, $taxClassId);
                continue;
            }

            if (isset($item['product_id']) && is_numeric($item['product_id']) && (int)$item['product_id'] > 0) {
                $productIdOrSku = (int)$item['product_id'];
            } elseif (isset($item['sku']) && trim((string)$item['sku']) !== '') {
                $productIdOrSku = trim((string)$item['sku']);
            } else {
                throw new LocalizedException(__('Queued order item %1 has no product_id or sku.', $index + 1));
            }

            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            $payload = $this->cartService->addProduct($quoteId, $productIdOrSku, $qty, $options);

            $hasPrice = isset($item['price']) && is_numeric($item['price']);
            $lineNote = isset($item['note']) ? trim((string)$item['note']) : '';
            if ($hasPrice || $lineNote !== '') {
                $itemId = $this->resolveItemId($payload, $item);
                if ($itemId !== null) {
                    $this->cartService->updateItem(
                        $quoteId,
                        $itemId,
                        null,
                        $hasPrice ? (float)$item['price'] : null,
                        $lineNote !== '' ? $lineNote : null
                    );
                }
            }
        }
    }

    private function applyDiscounts(int $quoteId, array $discounts): void
    {
        if ($discounts === []) {
            return;
        }

        $coupon = isset($discounts['coupon_code']) ? trim((string)$discounts['coupon_code']) : '';
        if ($coupon !== '') {
            $this->discountService->applyCoupon($quoteId, $coupon);
        }

        $cartDiscount = $discounts['cart'] ?? null;
        if (is_array($cartDiscount)
            && isset($cartDiscount['type'], $cartDiscount['value'])
            && is_numeric($cartDiscount['value'])
        ) {
            $this->discountService->applyCartDiscount(
                $quoteId,
                (string)$cartDiscount['type'],
                (float)$cartDiscount['value']
            );
        }

        $itemDiscounts = is_array($discounts['items'] ?? null) ? $discounts['items'] : [];
        if ($itemDiscounts === []) {
            return;
        }

        $cartPayload = $this->cartService->get($quoteId);
        $cartItems = is_array($cartPayload['items'] ?? null) ? array_values($cartPayload['items']) : [];

        foreach ($itemDiscounts as $itemDiscount) {
            if (!is_array($itemDiscount)
                || !isset($itemDiscount['item_index'], $itemDiscount['type'], $itemDiscount['value'])
                || !is_numeric($itemDiscount['item_index'])
                || !is_numeric($itemDiscount['value'])
            ) {
                continue;
            }
            $target = $cartItems[(int)$itemDiscount['item_index']] ?? null;
            if (!is_array($target) || !isset($target['item_id'])) {
                continue;
            }
            $this->discountService->applyItemDiscount(
                $quoteId,
                (int)$target['item_id'],
                (string)$itemDiscount['type'],
                (float)$itemDiscount['value']
            );
        }
    }

    private function resolveItemId(array $cartPayload, array $queuedItem): ?int
    {
        $items = is_array($cartPayload['items'] ?? null) ? array_values($cartPayload['items']) : [];
        if ($items === []) {
            return null;
        }

        $sku = isset($queuedItem['sku']) ? trim((string)$queuedItem['sku']) : '';
        $productId = isset($queuedItem['product_id']) && is_numeric($queuedItem['product_id'])
            ? (int)$queuedItem['product_id']
            : 0;

        for ($i = count($items) - 1; $i >= 0; $i--) {
            $line = $items[$i];
            if (!is_array($line) || !isset($line['item_id'])) {
                continue;
            }
            if ($productId > 0 && (int)($line['product_id'] ?? 0) === $productId) {
                return (int)$line['item_id'];
            }
            if ($sku !== '' && (string)($line['sku'] ?? '') === $sku) {
                return (int)$line['item_id'];
            }
        }

        $last = end($items);

        return is_array($last) && isset($last['item_id']) ? (int)$last['item_id'] : null;
    }

    private function findSyncedOrderIds(array $clientUuids): array
    {
        if ($clientUuids === []) {
            return [];
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(PosOrderInterface::CLIENT_UUID, $clientUuids, 'in')
            ->setPageSize(count($clientUuids))
            ->create();

        $map = [];
        foreach ($this->posOrderRepository->getList($searchCriteria)->getItems() as $item) {
            if (!$item instanceof PosOrderInterface) {
                continue;
            }
            $uuid = trim((string)$item->getClientUuid());
            if ($uuid === '' && count($clientUuids) === 1) {
                $uuid = $clientUuids[0];
            }
            if ($uuid !== '' && !isset($map[$uuid])) {
                $map[$uuid] = (int)$item->getOrderId();
            }
        }

        return $map;
    }

    private function resolveIncrementId(int $orderId): ?string
    {
        try {
            $incrementId = $this->orderRepository->get($orderId)->getIncrementId();

            return $incrementId !== null && $incrementId !== '' ? (string)$incrementId : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
