<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForSkuInterface;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Panth\MagePos\Api\CashMovementRepositoryInterface;
use Panth\MagePos\Api\Data\CashMovementInterface;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Model\CashMovementFactory;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\CollectionFactory as PaymentMethodCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\CollectionFactory as PosOrderPaymentCollectionFactory;
use Psr\Log\LoggerInterface;
use Panth\MagePos\Model\NameDecoder;

class RefundService
{
    private const EPSILON = 0.005;

    private const SEARCH_LIMIT = 25;

    private const SESSION_KEY_POS_SESSION_ID = 'panth_pos_session_id';

    public function __construct(
        private readonly AuthService $authService,
        private readonly SessionManagerInterface $sessionManager,
        private readonly SessionRepositoryInterface $sessionRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly CreditmemoFactory $creditmemoFactory,
        private readonly CreditmemoManagementInterface $creditmemoManagement,
        private readonly RegisterRepositoryInterface $registerRepository,
        private readonly PaymentMethodCollectionFactory $paymentMethodCollectionFactory,
        private readonly PosOrderPaymentCollectionFactory $posOrderPaymentCollectionFactory,
        private readonly CashMovementFactory $cashMovementFactory,
        private readonly CashMovementRepositoryInterface $cashMovementRepository,
        private readonly ModuleManager $moduleManager,
        private readonly LoggerInterface $logger,

        private readonly ?GetSourceItemsBySkuInterface $getSourceItemsBySku = null,
        private readonly ?SourceItemsSaveInterface $sourceItemsSave = null,
        private readonly ?IsSourceItemManagementAllowedForSkuInterface $isSourceItemManagementAllowed = null
    ) {
    }

    public function searchOrders(string $query): array
    {
        $this->authService->requireUser();
        $this->authService->requirePermission('can_refund');
        $query = trim($query);

        $collection = $this->orderCollectionFactory->create();
        $collection->getSelect()->joinLeft(
            ['pos_order' => $collection->getTable('panth_pos_order')],
            'pos_order.order_id = main_table.entity_id',
            [
                'pos_order_id' => 'pos_order_id',
                'receipt_number' => 'receipt_number',
                'pos_session_id' => 'session_id',
                'pos_register_id' => 'register_id',
            ]
        );

        $collection->getSelect()->where('pos_order.pos_order_id IS NOT NULL');
        $registerId = $this->getCurrentRegisterId();
        if ($registerId !== null) {
            $collection->getSelect()->where('pos_order.register_id = ?', $registerId);
        }

        if ($query !== '') {
            $collection->getSelect()->where(
                'main_table.increment_id LIKE ? OR main_table.customer_email LIKE ? OR pos_order.receipt_number LIKE ?',
                '%' . $this->escapeLikeValue($query) . '%'
            );
        }

        $collection->getSelect()
            ->order('main_table.created_at DESC')
            ->limit(self::SEARCH_LIMIT);

        $orders = [];

        foreach ($collection as $order) {
            $orders[] = $this->buildOrderRow($order);
        }

        return ['orders' => $orders];
    }

    public function preview(int $orderId, array $items): array
    {
        $this->authService->requireUser();
        $this->authService->requirePermission('can_refund');

        $this->assertPosOrderInScope($orderId);
        $order = $this->orderRepository->get($orderId);
        if (!$order->canCreditmemo()) {
            throw new LocalizedException(__('Order %1 cannot be refunded.', $order->getIncrementId()));
        }

        $qtys = $this->normaliseQtys($items);
        $creditmemo = $this->creditmemoFactory->createByOrder($order, $qtys !== [] ? ['qtys' => $qtys] : []);

        return [
            'refund_total' => round((float)$creditmemo->getGrandTotal(), 2),
            'items' => $this->buildPreviewItems($creditmemo),
            'currency' => (string)$order->getOrderCurrencyCode(),
        ];
    }

    private function buildPreviewItems(Creditmemo $creditmemo): array
    {
        $items = [];
        foreach ($creditmemo->getAllItems() as $creditmemoItem) {
            $orderItemId = (int)$creditmemoItem->getOrderItemId();
            if ($orderItemId <= 0) {
                continue;
            }
            $items[$orderItemId] = [
                'qty' => round((float)$creditmemoItem->getQty(), 4),
                'amount' => $this->creditmemoItemAmount($creditmemoItem),
            ];
        }

        return $items;
    }

    private function creditmemoItemAmount(\Magento\Sales\Model\Order\Creditmemo\Item $creditmemoItem): float
    {
        $rowTotal = (float)$creditmemoItem->getRowTotalInclTax();
        if ($rowTotal <= 0.0) {
            $rowTotal = (float)$creditmemoItem->getRowTotal()
                + (float)$creditmemoItem->getTaxAmount()
                + (float)$creditmemoItem->getDiscountTaxCompensationAmount();
        }
        $amount = $rowTotal - (float)$creditmemoItem->getDiscountAmount();

        return round(max(0.0, $amount), 2);
    }

    private function normaliseQtys(array $items): array
    {
        $qtys = [];
        foreach ($items as $itemId => $qty) {
            $qty = (float)$qty;
            if ($qty > 0) {
                $qtys[(int)$itemId] = $qty;
            }
        }

        return $qtys;
    }

    public function refund(int $orderId, array $items, array $payments, bool $restock, string $reason): array
    {
        $user = $this->authService->requireUser();
        $this->authService->requirePermission('can_refund');

        $this->assertPosOrderInScope($orderId);
        $order = $this->orderRepository->get($orderId);
        if (!$order->canCreditmemo()) {
            throw new LocalizedException(__('Order %1 cannot be refunded.', $order->getIncrementId()));
        }

        $qtys = $this->normaliseQtys($items);

        $creditmemo = $this->creditmemoFactory->createByOrder($order, $qtys !== [] ? ['qtys' => $qtys] : []);
        if (round((float)$creditmemo->getGrandTotal(), 2) <= 0) {
            throw new LocalizedException(__('There is nothing left to refund on order %1.', $order->getIncrementId()));
        }

        $sourceCode = $restock ? $this->getRegisterSourceCode() : null;
        $msiRestock = $sourceCode !== null && $this->isMsiRestockAvailable();
        foreach ($creditmemo->getAllItems() as $creditmemoItem) {
            if ($msiRestock && $this->isSourceManaged((string)$creditmemoItem->getSku())) {
                $creditmemoItem->setBackToStock(false);
            } else {
                $creditmemoItem->setBackToStock($restock);
            }
        }

        $reason = trim($reason);
        if ($reason !== '') {
            $creditmemo->addComment((string)__('POS refund: %1', $reason), false, false);
        }

        $refundTotal = round((float)$creditmemo->getGrandTotal(), 2);
        [$rows, $cashTotal] = $this->validateRefundPayments($payments, $refundTotal);

        $session = null;
        if ($cashTotal > 0) {
            $session = $this->getOpenSession();
            if ($session === null) {
                throw new LocalizedException(__('An open register session is required to refund cash.'));
            }
        }

        $this->creditmemoManagement->refund($creditmemo, true);

        if ($msiRestock) {
            $this->returnQtyToRegisterSource($creditmemo, (string)$sourceCode, $order->getIncrementId());
        }

        if ($session !== null) {
            foreach ($rows as $row) {
                if ($row['type'] !== CheckoutService::TYPE_CASH) {
                    continue;
                }
                try {
                    $movement = $this->cashMovementFactory->create();
                    $movement->setSessionId((int)$session->getSessionId());
                    $movement->setUserId((int)$user->getUserId());
                    $movement->setType(CashMovementInterface::TYPE_REFUND);
                    $movement->setAmount(-round((float)$row['amount'], 2));
                    $movement->setReason(
                        trim('Refund ' . $order->getIncrementId() . ($reason !== '' ? ' - ' . $reason : ''))
                    );
                    $movement->setOrderId((int)$order->getEntityId());
                    $this->cashMovementRepository->save($movement);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        '[Panth_MagePos] Failed to record refund cash movement for order '
                        . $order->getIncrementId() . ': ' . $e->getMessage(),
                        ['exception' => $e]
                    );
                }
            }
        }

        return [
            'creditmemo_id' => (int)$creditmemo->getEntityId(),
            'creditmemo_increment_id' => (string)$creditmemo->getIncrementId(),
            'order_id' => (int)$order->getEntityId(),
            'order_increment_id' => (string)$order->getIncrementId(),
            'refunded_total' => $refundTotal,
            'cash_refunded' => $cashTotal,
            'restocked' => $restock,
        ];
    }

    private function validateRefundPayments(array $payments, float $refundTotal): array
    {
        if ($payments === []) {
            return [[], 0.0];
        }

        $methods = $this->loadActiveMethods();
        $rows = [];
        $cashTotal = 0.0;
        $total = 0.0;

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                throw new LocalizedException(__('Invalid refund payment row.'));
            }
            $code = trim((string)($payment['method_code'] ?? ''));
            if ($code === '' || !isset($methods[$code])) {
                throw new LocalizedException(__('Unknown POS payment method "%1".', $code));
            }
            $amount = round((float)($payment['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new LocalizedException(__('Refund amounts must be greater than zero.'));
            }
            $method = $methods[$code];
            $reference = trim((string)($payment['reference'] ?? ''));
            $rows[] = [
                'method_code' => $code,
                'method_title' => $method->getTitle(),
                'type' => $method->getType(),
                'amount' => $amount,
                'reference' => $reference !== '' ? $reference : null,
            ];
            $total += $amount;
            if ($method->getType() === CheckoutService::TYPE_CASH) {
                $cashTotal += $amount;
            }
        }

        $total = round($total, 2);
        if (abs($total - $refundTotal) > self::EPSILON) {
            throw new LocalizedException(
                __('Refund payments (%1) must equal the refund total of %2.', $total, $refundTotal)
            );
        }

        return [$rows, round($cashTotal, 2)];
    }

    private function loadActiveMethods(): array
    {
        $methods = [];
        $collection = $this->paymentMethodCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        foreach ($collection as $method) {
            $methods[$method->getCode()] = $method;
        }
        return $methods;
    }

    private function isMsiRestockAvailable(): bool
    {
        return $this->getSourceItemsBySku !== null
            && $this->sourceItemsSave !== null
            && $this->moduleManager->isEnabled('Magento_InventorySales')
            && $this->moduleManager->isEnabled('Magento_InventoryApi');
    }

    private function getRegisterSourceCode(): ?string
    {
        $session = $this->getOpenSession();
        if ($session === null) {
            return null;
        }
        try {
            $register = $this->registerRepository->getById((int)$session->getRegisterId());
        } catch (NoSuchEntityException $e) {
            return null;
        }
        $sourceCode = trim((string)$register->getData('source_code'));

        return $sourceCode !== '' ? $sourceCode : null;
    }

    private function isSourceManaged(string $sku): bool
    {
        if ($sku === '') {
            return false;
        }
        if ($this->isSourceItemManagementAllowed === null) {
            return true;
        }
        try {
            return $this->isSourceItemManagementAllowed->execute($sku);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function returnQtyToRegisterSource(Creditmemo $creditmemo, string $sourceCode, string $orderIncrementId): void
    {
        $qtyBySku = [];
        foreach ($creditmemo->getAllItems() as $creditmemoItem) {
            if (!$creditmemoItem->getBackToStock()) {
                $sku = (string)$creditmemoItem->getSku();
                $qty = (float)$creditmemoItem->getQty();
                if ($sku !== '' && $qty > 0 && $this->isSourceManaged($sku)) {
                    $qtyBySku[$sku] = ($qtyBySku[$sku] ?? 0.0) + $qty;
                }
            }
        }

        foreach ($qtyBySku as $sku => $qty) {
            try {
                $sourceItem = $this->findSourceItem($sku, $sourceCode);
                if ($sourceItem === null) {
                    $this->logger->warning(sprintf(
                        '[Panth_MagePos] No source item for SKU %s at source %s; refund restock skipped (order %s).',
                        $sku,
                        $sourceCode,
                        $orderIncrementId
                    ));
                    continue;
                }
                $newQty = (float)$sourceItem->getQuantity() + $qty;
                $sourceItem->setQuantity($newQty);
                if ($newQty > 0) {
                    $sourceItem->setStatus(SourceItemInterface::STATUS_IN_STOCK);
                }
                $this->sourceItemsSave->execute([$sourceItem]);
            } catch (\Throwable $e) {
                $this->logger->error(
                    '[Panth_MagePos] MSI refund restock failed for SKU ' . $sku
                    . ' at source ' . $sourceCode . ' (order ' . $orderIncrementId . '): ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }
    }

    private function findSourceItem(string $sku, string $sourceCode): ?SourceItemInterface
    {
        foreach ($this->getSourceItemsBySku->execute($sku) as $sourceItem) {
            if ($sourceItem->getSourceCode() === $sourceCode) {
                return $sourceItem;
            }
        }

        return null;
    }

    private function buildRefundablePerLine(Order $order): array
    {
        if (!$order->canCreditmemo()) {
            return [];
        }
        try {
            $creditmemo = $this->creditmemoFactory->createByOrder($order);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Panth_MagePos] Could not build refund preview for order '
                . $order->getIncrementId() . ': ' . $e->getMessage()
            );
            return [];
        }

        return $this->buildPreviewItems($creditmemo);
    }

    private function buildOrderRow(Order $order): array
    {
        $refundable = $this->buildRefundablePerLine($order);

        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $itemId = (int)$item->getId();
            $qtyOrdered = (float)$item->getQtyOrdered();
            $qtyRefunded = (float)$item->getQtyRefunded();
            $qtyAvailable = max(0.0, $qtyOrdered - $qtyRefunded);
            $line = $refundable[$itemId] ?? null;
            $refundableLine = $line !== null ? $line['amount'] : 0.0;
            $refundableQty = $line !== null ? $line['qty'] : $qtyAvailable;
            $refundableUnit = $refundableQty > 0
                ? round($refundableLine / $refundableQty, 2)
                : round((float)($item->getPriceInclTax() ?: $item->getPrice()), 2);
            $items[] = [
                'item_id' => $itemId,
                'sku' => (string)$item->getSku(),
                'name' => NameDecoder::decode($item->getName()),
                'qty_ordered' => $qtyOrdered,
                'qty_refunded' => $qtyRefunded,
                'qty_available' => $qtyAvailable,
                'price' => round((float)($item->getPriceInclTax() ?: $item->getPrice()), 2),
                'row_total' => round((float)($item->getRowTotalInclTax() ?: $item->getRowTotal()), 2),

                'refundable_total' => $refundableLine,
                'refundable_unit' => $refundableUnit,
            ];
        }

        $payments = [];
        $posOrderId = (int)$order->getData('pos_order_id');
        if ($posOrderId > 0) {
            $paymentRows = $this->posOrderPaymentCollectionFactory->create();
            $paymentRows->addFieldToFilter('pos_order_id', $posOrderId);
            foreach ($paymentRows as $paymentRow) {
                $payments[] = [
                    'method_code' => $paymentRow->getMethodCode(),
                    'method_title' => $paymentRow->getMethodTitle(),
                    'amount' => round((float)$paymentRow->getAmount(), 2),
                    'reference' => $paymentRow->getReference(),
                    'is_change' => (int)$paymentRow->getIsChange(),
                ];
            }
        }

        $customerName = trim((string)$order->getCustomerFirstname() . ' ' . (string)$order->getCustomerLastname());

        return [
            'order_id' => (int)$order->getEntityId(),
            'increment_id' => (string)$order->getIncrementId(),
            'receipt_number' => $order->getData('receipt_number'),
            'created_at' => (string)$order->getCreatedAt(),
            'status' => (string)$order->getStatus(),
            'customer_name' => $customerName !== '' ? $customerName : (string)__('Guest'),
            'customer_email' => (string)$order->getCustomerEmail(),
            'grand_total' => round((float)$order->getGrandTotal(), 2),
            'total_refunded' => round((float)$order->getTotalRefunded(), 2),
            'can_refund' => $order->canCreditmemo(),
            'items' => $items,
            'payments' => $payments,
        ];
    }

    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function assertPosOrderInScope(int $orderId): void
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->getSelect()->join(
            ['pos_order' => $collection->getTable('panth_pos_order')],
            'pos_order.order_id = main_table.entity_id',
            []
        );
        $collection->getSelect()->where('main_table.entity_id = ?', $orderId);
        $registerId = $this->getCurrentRegisterId();
        if ($registerId !== null) {
            $collection->getSelect()->where('pos_order.register_id = ?', $registerId);
        }
        if ((int)$collection->getSize() === 0) {
            throw new NoSuchEntityException(__('The requested order does not exist.'));
        }
    }

    private function getCurrentRegisterId(): ?int
    {
        $session = $this->getOpenSession();

        return $session !== null ? (int)$session->getRegisterId() : null;
    }

    private function getOpenSession(): ?SessionInterface
    {
        $sessionId = (int)($this->sessionManager->getData(self::SESSION_KEY_POS_SESSION_ID) ?: 0);
        if ($sessionId <= 0) {
            return null;
        }
        try {
            $session = $this->sessionRepository->getById($sessionId);
        } catch (NoSuchEntityException $e) {
            return null;
        }

        return $session->getStatus() === SessionInterface::STATUS_OPEN ? $session : null;
    }
}
