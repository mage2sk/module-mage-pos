<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\ShipmentExtensionFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Panth\MagePos\Api\CashMovementRepositoryInterface;
use Panth\MagePos\Api\Data\CashMovementInterface;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\Data\RegisterInterface;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\PosOrderPaymentRepositoryInterface;
use Panth\MagePos\Api\PosOrderRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Model\CashMovementFactory;
use Panth\MagePos\Model\Payment\ProcessorPool;
use Panth\MagePos\Model\PosOrder;
use Panth\MagePos\Model\PosOrderFactory;
use Panth\MagePos\Model\PosOrderPaymentFactory;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\CollectionFactory as PaymentMethodCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosOrder\CollectionFactory as PosOrderCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\CollectionFactory as PosOrderPaymentCollectionFactory;
use Panth\MagePos\Model\SessionFactory;
use Psr\Log\LoggerInterface;

class CheckoutService
{
    public const PAYMENT_METHOD_CODE = 'panth_pos';

    public const TYPE_CASH = 'cash';
    public const TYPE_OFFLINE = 'offline';
    public const TYPE_ONLINE = 'online';

    private const EPSILON = 0.005;

    public const DEFAULT_PAYMENT_URL_TEMPLATE = '';

    private const UNSUPPORTED_PAYMENT_URL_TEMPLATE = '{{base_url}}pos/pay/%increment_id%?amount=%amount%';

    private const SESSION_KEY_POS_SESSION_ID = 'panth_pos_session_id';

    private const ITEM_CUSTOM_NAME_KEY = 'pos_custom_name';

    public function __construct(
        private readonly AuthService $authService,
        private readonly Config $config,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly SessionManagerInterface $sessionManager,
        private readonly SessionRepositoryInterface $sessionRepository,
        private readonly SessionFactory $sessionFactory,
        private readonly RegisterRepositoryInterface $registerRepository,
        private readonly PaymentMethodCollectionFactory $paymentMethodCollectionFactory,
        private readonly PosOrderFactory $posOrderFactory,
        private readonly PosOrderRepositoryInterface $posOrderRepository,
        private readonly PosOrderCollectionFactory $posOrderCollectionFactory,
        private readonly PosOrderPaymentFactory $posOrderPaymentFactory,
        private readonly PosOrderPaymentRepositoryInterface $posOrderPaymentRepository,
        private readonly PosOrderPaymentCollectionFactory $posOrderPaymentCollectionFactory,
        private readonly CashMovementFactory $cashMovementFactory,
        private readonly CashMovementRepositoryInterface $cashMovementRepository,
        private readonly ProcessorPool $processorPool,
        private readonly QuotePreparer $quotePreparer,
        private readonly ModuleManager $moduleManager,
        private readonly LoggerInterface $logger,

        private readonly ?ShipmentFactory $shipmentFactory = null,
        private readonly ?ShipmentExtensionFactory $shipmentExtensionFactory = null
    ) {
    }

    public function placeOrder(int $quoteId, array $payments, array $opts = []): array
    {
        $user = $this->authService->requireUser();

        $clientUuid = isset($opts['client_uuid']) && trim((string)$opts['client_uuid']) !== ''
            ? trim((string)$opts['client_uuid'])
            : null;
        if ($clientUuid !== null) {
            $existing = $this->findPosOrderByClientUuid($clientUuid);
            if ($existing !== null) {
                return $this->buildResultFromExistingPosOrder($existing);
            }
        }

        $quote = $this->quoteRepository->getActive($quoteId);

        if ($quote->getData(CartService::QUOTE_POS_REGISTER_ID) === null) {
            throw new NoSuchEntityException(__('Cart %1 no longer exists.', $quoteId));
        }
        $storeId = (int)$quote->getStoreId();

        if (!$this->config->isEnabled($storeId)) {
            throw new LocalizedException(__('The POS is disabled for this store.'));
        }
        if (!$quote->getItemsCount()) {
            throw new LocalizedException(__('The cart is empty.'));
        }

        $session = $this->resolveOpenSession($user, $storeId, $opts);
        $quoteRegisterId = (int)$quote->getData(CartService::QUOTE_POS_REGISTER_ID);
        if ($quoteRegisterId > 0 && $quoteRegisterId !== (int)$session->getRegisterId()) {
            throw new NoSuchEntityException(__('Cart %1 no longer exists.', $quoteId));
        }
        $register = $this->registerRepository->getById((int)$session->getRegisterId());

        $this->prepareQuote($quote, $storeId);
        $grandTotal = round((float)$quote->getGrandTotal(), 2);

        $methods = $this->loadActiveMethods();
        [$rows, $changeDue] = $this->validatePayments($payments, $grandTotal, $methods);

        $receiptNumber = $this->generateReceiptNumber($register, $session);

        $receiptToken = bin2hex(random_bytes(16));

        $this->setQuotePaymentData($quote, $rows, $changeDue, $session, $register, $user, $receiptNumber);
        $this->storePosDiscountAmount($quote);
        $this->quoteRepository->save($quote);

        $order = $this->cartManagement->submit($quote);
        if (!$order || !$order->getEntityId()) {
            throw new LocalizedException(__('The order could not be created.'));
        }

        $note = trim((string)($opts['note'] ?? ''));
        if ($note !== '') {
            $order->setCustomerNote($this->config->getOrderNotePrefix($storeId) . ': ' . $note);
            $order->setCustomerNoteNotify(false);
        }

        $onlineRows = array_values(array_filter($rows, static fn (array $row): bool => $row['type'] === self::TYPE_ONLINE));
        $onlineResults = [];
        $allOnlinePaid = true;
        if ($onlineRows !== []) {
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PENDING_PAYMENT));
            [$onlineResults, $allOnlinePaid, $rows] = $this->processOnlinePayments($order, $rows, $methods);
        }

        if (($onlineRows === [] || $allOnlinePaid)
            && $this->config->isAutoInvoiceOffline($storeId)
            && $order->canInvoice()
        ) {
            $this->invoiceOffline($order);
        }

        $this->orderRepository->save($order);

        if ($onlineRows === [] || $allOnlinePaid) {
            $this->deductFromRegisterSource($order, $register);
        }

        $this->persistPosRecords(
            $order,
            $rows,
            $changeDue,
            $session,
            $register,
            $user,
            $receiptNumber,
            $receiptToken,
            $clientUuid,
            $opts
        );

        return [
            'order_id' => (int)$order->getEntityId(),
            'increment_id' => (string)$order->getIncrementId(),
            'change_due' => $changeDue,
            'receipt_number' => $receiptNumber,
            'receipt_token' => $receiptToken,
            'online' => $onlineResults,
        ];
    }

    private function resolveOpenSession(PosUserInterface $user, int $storeId, array $opts): SessionInterface
    {
        $sessionId = (int)($this->sessionManager->getData(self::SESSION_KEY_POS_SESSION_ID) ?: 0);
        if ($sessionId > 0) {
            try {
                $session = $this->sessionRepository->getById($sessionId);
                if ($session->getStatus() === SessionInterface::STATUS_OPEN) {
                    return $session;
                }
            } catch (NoSuchEntityException $e) {
            }
        }

        if ($this->config->isSessionRequired($storeId)) {
            throw new LocalizedException(__('An open register session is required before placing orders.'));
        }

        $registerId = (int)($opts['register_id'] ?? 0);
        if ($registerId <= 0) {
            $registerId = (int)($this->config->getDefaultRegisterId($storeId) ?? 0);
        }
        if ($registerId <= 0) {
            throw new LocalizedException(
                __('No register session is open and no default register is configured.')
            );
        }

        $session = $this->sessionFactory->create();
        $session->setRegisterId($registerId);
        $session->setUserId((int)$user->getUserId());
        $session->setStatus(SessionInterface::STATUS_OPEN);
        $session->setOpeningFloat(0.0);
        $session->setNote('Auto-opened at checkout (session requirement disabled)');
        $this->sessionRepository->save($session);
        $this->sessionManager->setData(self::SESSION_KEY_POS_SESSION_ID, (int)$session->getSessionId());

        return $session;
    }

    private function validatePayments(array $payments, float $grandTotal, array $methods): array
    {
        $rows = [];
        $cashTotal = 0.0;
        $otherTotal = 0.0;

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                throw new LocalizedException(__('Invalid payment row.'));
            }
            $code = trim((string)($payment['method_code'] ?? ''));
            if ($code === '' || !isset($methods[$code])) {
                throw new LocalizedException(__('Unknown POS payment method "%1".', $code));
            }
            $amount = round((float)($payment['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new LocalizedException(__('Payment amounts must be greater than zero.'));
            }
            $method = $methods[$code];
            $reference = trim((string)($payment['reference'] ?? ''));
            if ($reference === '' && $method->getRequiresReference()) {
                throw new LocalizedException(__('A reference is required for %1.', $method->getTitle()));
            }
            $rows[] = [
                'method_code' => $code,
                'method_title' => $method->getTitle(),
                'type' => $method->getType(),
                'amount' => $amount,
                'reference' => $reference !== '' ? $reference : null,
            ];
            if ($method->getType() === self::TYPE_CASH) {
                $cashTotal += $amount;
            } else {
                $otherTotal += $amount;
            }
        }

        if ($rows === []) {
            throw new LocalizedException(__('At least one payment is required.'));
        }

        $tendered = round($cashTotal + $otherTotal, 2);
        if ($cashTotal > 0) {
            if ($tendered + self::EPSILON < $grandTotal) {
                throw new LocalizedException(
                    __('Insufficient payment: %1 tendered for an order total of %2.', $tendered, $grandTotal)
                );
            }
            if ($otherTotal > $grandTotal + self::EPSILON) {
                throw new LocalizedException(__('Change can only be given on cash payments.'));
            }
            $changeDue = round($tendered - $grandTotal, 2);
            if ($changeDue < 0.01) {
                $changeDue = 0.0;
            }

            if ($changeDue > 0
                && $otherTotal > self::EPSILON
                && $otherTotal + self::EPSILON >= $grandTotal
            ) {
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => $row['type'] !== self::TYPE_CASH
                ));
                $changeDue = 0.0;
            }
        } else {
            if (abs($tendered - $grandTotal) > self::EPSILON) {
                throw new LocalizedException(
                    __('Split payments (%1) must equal the order total of %2.', $tendered, $grandTotal)
                );
            }
            $changeDue = 0.0;
        }

        return [$rows, $changeDue];
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

    private function generateReceiptNumber(RegisterInterface $register, SessionInterface $session): string
    {
        $collection = $this->posOrderCollectionFactory->create();
        $collection->addFieldToFilter('session_id', (int)$session->getSessionId());
        $seq = $collection->getSize() + 1;

        return sprintf('%s-%d-%d', $register->getCode(), (int)$session->getSessionId(), $seq);
    }

    private function prepareQuote(Quote $quote, int $storeId): void
    {
        $quote->setStoreId($storeId);
        $this->quotePreparer->prepare($quote);
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->applyCustomItemNames($quote);
    }

    private function applyCustomItemNames(Quote $quote): void
    {
        foreach ($quote->getAllItems() as $item) {
            $raw = $item->getAdditionalData();
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            $customName = is_array($data) ? ($data[self::ITEM_CUSTOM_NAME_KEY] ?? null) : null;
            if (is_string($customName) && $customName !== '') {
                $item->setName($customName);
            }
        }
    }

    private function storePosDiscountAmount(Quote $quote): void
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        $amount = round(abs((float)$address->getData('pos_discount_amount')), 2);
        $baseAmount = round(abs((float)$address->getData('base_pos_discount_amount')), 2);
        if ($amount <= 0 && $baseAmount <= 0) {
            return;
        }
        $payment = $quote->getPayment();
        $payment->setAdditionalInformation('pos_discount_amount', $amount);
        $payment->setAdditionalInformation('base_pos_discount_amount', $baseAmount > 0 ? $baseAmount : $amount);
    }

    private function setQuotePaymentData(
        Quote $quote,
        array $rows,
        float $changeDue,
        SessionInterface $session,
        RegisterInterface $register,
        PosUserInterface $user,
        string $receiptNumber
    ): void {
        $infoRows = [];
        foreach ($rows as $row) {
            $infoRows[] = [
                'method_code' => $row['method_code'],
                'method_title' => $row['method_title'],
                'amount' => $row['amount'],
                'reference' => $row['reference'],
                'is_change' => 0,
            ];
        }
        if ($changeDue > 0) {
            $cashRow = $this->getFirstCashRow($rows);
            $infoRows[] = [
                'method_code' => $cashRow['method_code'],
                'method_title' => $cashRow['method_title'],
                'amount' => -$changeDue,
                'reference' => null,
                'is_change' => 1,
            ];
        }

        $payment = $quote->getPayment();
        $payment->setMethod(self::PAYMENT_METHOD_CODE);
        $payment->setAdditionalInformation('pos_payments', $infoRows);
        $payment->setAdditionalInformation('pos_register_id', (int)$register->getRegisterId());
        $payment->setAdditionalInformation('pos_session_id', (int)$session->getSessionId());
        $payment->setAdditionalInformation('pos_user', $user->getName());
        $payment->setAdditionalInformation('receipt_number', $receiptNumber);
    }

    private function processOnlinePayments(Order $order, array $rows, array $methods): array
    {
        $onlineResults = [];
        $allPaid = true;

        foreach ($rows as $index => $row) {
            if ($row['type'] !== self::TYPE_ONLINE) {
                continue;
            }
            $entry = ['method_code' => $row['method_code']];
            $processor = $this->processorPool->getForMethod($row['method_code']);
            if ($processor !== null) {
                try {
                    $result = $processor->process($order, $row);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        '[Panth_MagePos] Payment processor failed for ' . $row['method_code']
                        . ': ' . $e->getMessage(),
                        ['exception' => $e]
                    );
                    $result = ['status' => 'pending', 'reference' => null, 'redirect_url' => null];
                }
                if (($result['status'] ?? 'pending') !== 'paid') {
                    $allPaid = false;
                }
                if (!empty($result['reference'])) {
                    $rows[$index]['reference'] = (string)$result['reference'];
                }
                if (!empty($result['redirect_url'])) {
                    $entry['redirect_url'] = (string)$result['redirect_url'];
                }
            } else {
                $allPaid = false;
                $template = isset($methods[$row['method_code']])
                    ? trim((string)$methods[$row['method_code']]->getPaymentUrlTemplate())
                    : '';

                if ($template !== ''
                    && stripos($template, 'example.com') === false
                    && $template !== self::UNSUPPORTED_PAYMENT_URL_TEMPLATE
                ) {
                    $entry['payment_url'] = strtr($template, [
                        '{{base_url}}' => $this->getStoreBaseUrl($order),
                        '%increment_id%' => (string)$order->getIncrementId(),
                        '%amount%' => number_format((float)$row['amount'], 2, '.', ''),
                    ]);
                }
            }
            $onlineResults[] = $entry;
        }

        return [$onlineResults, $allPaid, $rows];
    }

    private function getStoreBaseUrl(Order $order): string
    {
        try {
            $baseUrl = (string)$order->getStore()->getBaseUrl(UrlInterface::URL_TYPE_LINK);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Panth_MagePos] Could not resolve the store base url for the payment link: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $baseUrl = '';
        }

        return rtrim($baseUrl, '/') . '/';
    }

    private function invoiceOffline(Order $order): void
    {
        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);

        if (abs((float)$order->getGrandTotal() - (float)$invoice->getGrandTotal()) > 0.0001) {
            $invoice->setGrandTotal((float)$order->getGrandTotal());
            $invoice->setBaseGrandTotal((float)$order->getBaseGrandTotal());
        }
        $invoice->register();
        $order->setIsInProcess(true);
        $this->transactionFactory->create()
            ->addObject($invoice)
            ->addObject($order)
            ->save();
    }

    private function persistPosRecords(
        Order $order,
        array $rows,
        float $changeDue,
        SessionInterface $session,
        RegisterInterface $register,
        PosUserInterface $user,
        string $receiptNumber,
        string $receiptToken,
        ?string $clientUuid,
        array $opts
    ): void {
        try {
            $posOrder = $this->posOrderFactory->create();
            $posOrder->setOrderId((int)$order->getEntityId());
            $posOrder->setSessionId((int)$session->getSessionId());
            $posOrder->setRegisterId((int)$register->getRegisterId());
            $posOrder->setPosUserId((int)$user->getUserId());
            $posOrder->setReceiptNumber($receiptNumber);

            $posOrder->setData('receipt_token', $receiptToken);
            $posOrder->setClientUuid($clientUuid);
            $posOrder->setIsOfflineSync(!empty($opts['is_offline_sync']) ? 1 : 0);
            $this->posOrderRepository->save($posOrder);
            $posOrderId = (int)$posOrder->getPosOrderId();

            $cashRow = $changeDue > 0 ? $this->getFirstCashRow($rows) : null;

            foreach ($rows as $row) {
                $paymentRow = $this->posOrderPaymentFactory->create();
                $paymentRow->setPosOrderId($posOrderId);
                $paymentRow->setMethodCode($row['method_code']);
                $paymentRow->setMethodTitle($row['method_title']);
                $paymentRow->setAmount((float)$row['amount']);
                $paymentRow->setReference($row['reference']);
                $paymentRow->setIsChange(0);
                $this->posOrderPaymentRepository->save($paymentRow);
            }
            if ($cashRow !== null) {
                $changeRow = $this->posOrderPaymentFactory->create();
                $changeRow->setPosOrderId($posOrderId);
                $changeRow->setMethodCode($cashRow['method_code']);
                $changeRow->setMethodTitle($cashRow['method_title']);
                $changeRow->setAmount(-$changeDue);
                $changeRow->setReference(null);
                $changeRow->setIsChange(1);
                $this->posOrderPaymentRepository->save($changeRow);
            }

            foreach ($rows as $row) {
                if ($row['type'] !== self::TYPE_CASH) {
                    continue;
                }
                $this->addSaleMovement(
                    $session,
                    $user,
                    (float)$row['amount'],
                    'Sale ' . $receiptNumber,
                    (int)$order->getEntityId()
                );
            }
            if ($changeDue > 0) {
                $this->addSaleMovement(
                    $session,
                    $user,
                    -$changeDue,
                    'Change ' . $receiptNumber,
                    (int)$order->getEntityId()
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Panth_MagePos] Failed to persist POS records for order '
                . $order->getIncrementId() . ': ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    private function addSaleMovement(
        SessionInterface $session,
        PosUserInterface $user,
        float $amount,
        string $reason,
        int $orderId
    ): void {
        $movement = $this->cashMovementFactory->create();
        $movement->setSessionId((int)$session->getSessionId());
        $movement->setUserId((int)$user->getUserId());
        $movement->setType(CashMovementInterface::TYPE_SALE);
        $movement->setAmount(round($amount, 2));
        $movement->setReason($reason);
        $movement->setOrderId($orderId);
        $this->cashMovementRepository->save($movement);
    }

    private function getFirstCashRow(array $rows): array
    {
        foreach ($rows as $row) {
            if ($row['type'] === self::TYPE_CASH) {
                return $row;
            }
        }

        return $rows[0];
    }

    private function findPosOrderByClientUuid(string $clientUuid): ?PosOrder
    {
        $collection = $this->posOrderCollectionFactory->create();
        $collection->addFieldToFilter('client_uuid', $clientUuid);
        $collection->setPageSize(1);

        $posOrder = $collection->getFirstItem();

        return $posOrder->getPosOrderId() ? $posOrder : null;
    }

    private function buildResultFromExistingPosOrder(PosOrder $posOrder): array
    {
        $order = $this->orderRepository->get($posOrder->getOrderId());

        $changeDue = 0.0;
        $paymentRows = $this->posOrderPaymentCollectionFactory->create();
        $paymentRows->addFieldToFilter('pos_order_id', (int)$posOrder->getPosOrderId());
        foreach ($paymentRows as $paymentRow) {
            if ((int)$paymentRow->getIsChange() === 1) {
                $changeDue += abs((float)$paymentRow->getAmount());
            }
        }

        return [
            'order_id' => (int)$order->getEntityId(),
            'increment_id' => (string)$order->getIncrementId(),
            'change_due' => round($changeDue, 2),
            'receipt_number' => $posOrder->getReceiptNumber(),
            'receipt_token' => (string)($posOrder->getData('receipt_token') ?? ''),
            'online' => [],
        ];
    }

    private function deductFromRegisterSource(Order $order, RegisterInterface $register): void
    {
        $sourceCode = trim((string)$register->getData('source_code'));
        if ($sourceCode === '') {
            return;
        }
        if ($this->shipmentFactory === null
            || $this->shipmentExtensionFactory === null
            || !$this->moduleManager->isEnabled('Magento_InventoryShipping')
            || !$this->moduleManager->isEnabled('Magento_InventorySales')
        ) {
            return;
        }
        if (!$order->canShip()) {
            return;
        }

        try {
            $shipment = $this->shipmentFactory->create($order);
            if (!$shipment->getTotalQty()) {
                return;
            }

            $extension = $shipment->getExtensionAttributes() ?: $this->shipmentExtensionFactory->create();

            if (method_exists($extension, 'setSourceCode')) {
                $extension->setSourceCode($sourceCode);
            }
            $shipment->setExtensionAttributes($extension);
            $shipment->register();

            $order->setIsInProcess(true);
            $this->transactionFactory->create()
                ->addObject($shipment)
                ->addObject($order)
                ->save();
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Panth_MagePos] MSI source deduction failed for order '
                . $order->getIncrementId() . ' at source ' . $sourceCode . ': ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
