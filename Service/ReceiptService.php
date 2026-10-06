<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\BlockFactory;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Api\Data\PosOrderInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Model\ResourceModel\PosOrder\CollectionFactory as PosOrderCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\CollectionFactory as PosOrderPaymentCollectionFactory;
use Panth\MagePos\Model\NameDecoder;

class ReceiptService
{
    private const EMAIL_TEMPLATE_ID = 'panth_pos_receipt';
    private const RECEIPT_TEMPLATE = 'Panth_MagePos::receipt.phtml';

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PosOrderCollectionFactory $posOrderCollectionFactory,
        private readonly PosOrderPaymentCollectionFactory $posOrderPaymentCollectionFactory,
        private readonly RegisterRepositoryInterface $registerRepository,
        private readonly PosUserRepositoryInterface $posUserRepository,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TimezoneInterface $timezone,
        private readonly BlockFactory $blockFactory,
        private readonly TransportBuilder $transportBuilder,
        private readonly Emulation $emulation,
        private readonly Escaper $escaper
    ) {
    }

    public function getReceiptData(int $orderId): array
    {
        $order = $this->orderRepository->get($orderId);
        $posOrder = $this->loadPosOrder($orderId);

        $storeId = (int)$order->getStoreId();
        $currencyCode = (string)($order->getOrderCurrencyCode() ?: $order->getBaseCurrencyCode());

        [$registerName, $registerCode, $receiptHeader, $receiptFooter] =
            $this->resolveRegisterAndTexts($posOrder, $storeId);
        [$cashierName, $cashierUsername] = $this->resolveCashier($posOrder);

        $customSku = $this->config->getCustomProductSku($storeId);
        $items = $this->buildItems($order, $storeId, $currencyCode, $customSku);

        $discount = round(abs((float)$order->getDiscountAmount()) + $this->getPosDiscountAmount($order), 2);
        $shipping = round((float)$order->getShippingAmount(), 2);
        $adjustment = round(
            (float)$order->getGrandTotal()
            - ((float)$order->getSubtotal() - $discount + (float)$order->getTaxAmount() + $shipping),
            2
        );
        $totals = [
            'subtotal' => (float)$order->getSubtotal(),
            'subtotal_formatted' => $this->formatMoney((float)$order->getSubtotal(), $storeId, $currencyCode),
            'discount' => $discount,
            'discount_formatted' => $discount > 0
                ? '-' . $this->formatMoney($discount, $storeId, $currencyCode)
                : '',
            'tax' => (float)$order->getTaxAmount(),
            'tax_formatted' => $this->formatMoney((float)$order->getTaxAmount(), $storeId, $currencyCode),
            'shipping' => $shipping,
            'shipping_formatted' => $shipping > 0 ? $this->formatMoney($shipping, $storeId, $currencyCode) : '',
            'adjustment' => $adjustment,
            'adjustment_formatted' => abs($adjustment) >= 0.01
                ? ($adjustment < 0 ? '-' : '') . $this->formatMoney(abs($adjustment), $storeId, $currencyCode)
                : '',
            'grand_total' => (float)$order->getGrandTotal(),
            'grand_total_formatted' => $this->formatMoney((float)$order->getGrandTotal(), $storeId, $currencyCode),
            'items_qty' => (float)$order->getTotalQtyOrdered(),
        ];

        $showTaxBreakdown = $this->config->isShowTaxBreakdown($storeId);
        $taxBreakdown = $showTaxBreakdown
            ? $this->buildTaxBreakdown($order, $storeId, $currencyCode)
            : [];

        [$payments, $changeDue] = $this->buildPayments($posOrder, $storeId, $currencyCode);

        [$customerName, $customerEmail] = $this->resolveCustomer($order, $storeId);

        return [
            'order_id' => (int)$order->getEntityId(),
            'increment_id' => (string)$order->getIncrementId(),
            'receipt_number' => $posOrder->getReceiptNumber(),

            'receipt_token' => (string)($posOrder->getData('receipt_token') ?? ''),
            'pos_user_id' => (int)$posOrder->getData('pos_user_id'),
            'date' => $this->formatOrderDate($order),
            'store_id' => $storeId,
            'store_name' => $this->resolveStoreName($storeId),
            'store_address' => $this->resolveStoreAddress($storeId),
            'logo_url' => $this->resolveLogoUrl($storeId),
            'header' => $receiptHeader,
            'footer' => $receiptFooter,
            'register_name' => $registerName,
            'register_code' => $registerCode,
            'cashier_name' => $cashierName,
            'cashier_username' => $cashierUsername,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'note' => (string)($order->getCustomerNote() ?: ''),
            'items' => $items,
            'totals' => $totals,
            'show_tax_breakdown' => $showTaxBreakdown,
            'tax_breakdown' => $taxBreakdown,
            'payments' => $payments,
            'change_due' => $changeDue,
            'change_due_formatted' => $changeDue > 0
                ? $this->formatMoney($changeDue, $storeId, $currencyCode)
                : '',
            'currency_code' => $currencyCode,
            'currency_symbol' => (string)$this->priceCurrency->getCurrencySymbol($storeId, $currencyCode),
        ];
    }

    private function getPosDiscountAmount(OrderInterface $order): float
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return 0.0;
        }
        $amount = (float)($payment->getAdditionalInformation()['pos_discount_amount'] ?? 0);

        return $amount > 0 ? $amount : 0.0;
    }

    public function renderHtml(int $orderId): string
    {
        $data = $this->getReceiptData($orderId);

        $block = $this->blockFactory->createBlock(Template::class);
        $block->setTemplate(self::RECEIPT_TEMPLATE);
        $block->setData('receipt', $data);
        return $block->toHtml();
    }

    public function email(int $orderId, string $to): void
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('Please provide a valid receipt email address.'));
        }

        $data = $this->getReceiptData($orderId);
        $storeId = (int)$data['store_id'];

        $vars = $data;
        $vars['receipt_logo_url'] = (string)$vars['logo_url'];
        unset($vars['logo_url']);

        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $this->transportBuilder
                ->setTemplateIdentifier(self::EMAIL_TEMPLATE_ID)
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars($vars)
                ->setFromByScope('sales', $storeId)
                ->addTo($to, $data['customer_name'] !== '' ? $data['customer_name'] : $to);
            $this->transportBuilder->getTransport()->sendMessage();
        } catch (NoSuchEntityException|LocalizedException $e) {
            throw new LocalizedException(__('Unable to send the receipt email: %1', $e->getMessage()), $e);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    private function loadPosOrder(int $orderId): PosOrderInterface
    {
        $collection = $this->posOrderCollectionFactory->create();
        $collection->addFieldToFilter(PosOrderInterface::ORDER_ID, $orderId)->setPageSize(1);

        $posOrder = $collection->getFirstItem();
        if (!$posOrder->getId()) {
            throw new NoSuchEntityException(
                __('No POS receipt exists for order id "%1".', $orderId)
            );
        }
        return $posOrder;
    }

    private function resolveRegisterAndTexts(PosOrderInterface $posOrder, int $storeId): array
    {
        $name = '';
        $code = '';
        $header = '';
        $footer = '';
        try {
            $register = $this->registerRepository->getById($posOrder->getRegisterId());
            $name = $register->getName();
            $code = $register->getCode();
            $header = trim((string)$register->getReceiptHeader());
            $footer = trim((string)$register->getReceiptFooter());
        } catch (NoSuchEntityException $e) {
        }
        if ($header === '') {
            $header = trim($this->config->getReceiptHeader($storeId));
        }
        if ($footer === '') {
            $footer = trim($this->config->getReceiptFooter($storeId));
        }
        return [$name, $code, $header, $footer];
    }

    private function resolveCashier(PosOrderInterface $posOrder): array
    {
        try {
            $cashier = $this->posUserRepository->getById($posOrder->getPosUserId());
            return [$cashier->getName(), $cashier->getUsername()];
        } catch (NoSuchEntityException $e) {
            return ['', ''];
        }
    }

    private function resolveCustomer(OrderInterface $order, int $storeId): array
    {
        $email = (string)($order->getCustomerEmail() ?: '');
        if ($email !== '' && strcasecmp($email, $this->config->getGuestEmail($storeId)) === 0) {
            return ['', ''];
        }
        $name = trim(
            (string)($order->getCustomerFirstname() ?: '') . ' ' . (string)($order->getCustomerLastname() ?: '')
        );
        return [$name, $email];
    }

    private function buildItems(OrderInterface $order, int $storeId, string $currencyCode, string $customSku): array
    {
        $items = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $qty = (float)$item->getQtyOrdered();
            $price = (float)($item->getPriceInclTax() ?: $item->getPrice());
            $originalPrice = (float)$item->getOriginalPrice();
            $rowTotal = (float)($item->getRowTotalInclTax() ?: $item->getRowTotal());
            $discount = abs((float)$item->getDiscountAmount());
            $productOptions = (array)$item->getProductOptions();
            $note = (string)($item->getData('pos_note') ?: '');
            if ($note === '') {
                $note = (string)($productOptions['pos_note'] ?? '');
            }
            $options = $this->buildItemOptions($productOptions);

            $items[] = [
                'item_id' => (int)$item->getItemId(),
                'sku' => (string)$item->getSku(),
                'name' => NameDecoder::decode($item->getName()),
                'qty' => $qty,
                'qty_formatted' => $this->formatQty($qty),
                'price' => $price,
                'price_formatted' => $this->formatMoney($price, $storeId, $currencyCode),
                'original_price' => $originalPrice,

                'original_price_formatted' => $originalPrice > $price
                    ? $this->formatMoney($originalPrice, $storeId, $currencyCode)
                    : '',
                'row_total' => $rowTotal,
                'row_total_formatted' => $this->formatMoney($rowTotal, $storeId, $currencyCode),
                'discount_amount' => $discount,
                'discount_formatted' => $discount > 0
                    ? '-' . $this->formatMoney($discount, $storeId, $currencyCode)
                    : '',
                'tax_amount' => (float)$item->getTaxAmount(),
                'is_custom' => strcasecmp((string)$item->getSku(), $customSku) === 0,
                'note' => $note,

                'options' => $options,

                'options_html' => $this->buildItemOptionsHtml($options),
            ];
        }
        return $items;
    }

    private function buildItemOptions(array $productOptions): array
    {
        $rows = [];

        foreach ((array)($productOptions['attributes_info'] ?? []) as $info) {
            if (is_array($info) && isset($info['label'], $info['value'])) {
                $rows[] = [
                    'label' => (string)$info['label'],
                    'value' => (string)$info['value'],
                ];
            }
        }

        foreach ((array)($productOptions['bundle_options'] ?? []) as $bundleOption) {
            if (!is_array($bundleOption)) {
                continue;
            }
            $parts = [];
            foreach ((array)($bundleOption['value'] ?? []) as $selection) {
                if (!is_array($selection)) {
                    continue;
                }
                $title = (string)($selection['title'] ?? '');
                if ($title === '') {
                    continue;
                }
                $selectionQty = (float)($selection['qty'] ?? 1);
                $parts[] = ($selectionQty > 0 ? $this->formatQty($selectionQty) . ' × ' : '') . $title;
            }
            if ($parts !== []) {
                $rows[] = [
                    'label' => (string)($bundleOption['label'] ?? ''),
                    'value' => implode(', ', $parts),
                ];
            }
        }

        foreach ((array)($productOptions['options'] ?? []) as $customOption) {
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
            $rows[] = [
                'label' => (string)($customOption['label'] ?? ''),
                'value' => $value,
            ];
        }

        return $rows;
    }

    private function buildItemOptionsHtml(array $options): string
    {
        $lines = [];
        foreach ($options as $option) {
            $label = trim($option['label']);
            $value = trim($option['value']);
            if ($value === '') {
                continue;
            }
            $lines[] = ($label !== '' ? $this->escaper->escapeHtml($label) . ': ' : '')
                . $this->escaper->escapeHtml($value);
        }
        if ($lines === []) {
            return '';
        }
        return '<span style="color:#999999;font-size:12px;">'
            . implode('<br />', $lines)
            . '</span><br />';
    }

    private function buildTaxBreakdown(OrderInterface $order, int $storeId, string $currencyCode): array
    {
        $byPercent = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $amount = (float)$item->getTaxAmount();
            if ($amount <= 0) {
                continue;
            }
            $key = number_format((float)$item->getTaxPercent(), 4, '.', '');
            $byPercent[$key] = ($byPercent[$key] ?? 0.0) + $amount;
        }
        ksort($byPercent, SORT_NUMERIC);

        $rows = [];
        foreach ($byPercent as $percent => $amount) {
            $percentLabel = rtrim(rtrim((string)$percent, '0'), '.');
            $rows[] = [
                'label' => (string)__('Tax (%1%)', $percentLabel === '' ? '0' : $percentLabel),
                'amount' => $amount,
                'amount_formatted' => $this->formatMoney($amount, $storeId, $currencyCode),
            ];
        }
        return $rows;
    }

    private function buildPayments(PosOrderInterface $posOrder, int $storeId, string $currencyCode): array
    {
        $collection = $this->posOrderPaymentCollectionFactory->create();
        $collection->addFieldToFilter('pos_order_id', (int)$posOrder->getPosOrderId());
        $collection->setOrder('payment_id', 'ASC');

        $payments = [];
        $changeDue = 0.0;

        foreach ($collection as $payment) {
            if ($payment->getIsChange()) {
                $changeDue += abs($payment->getAmount());
                continue;
            }
            $payments[] = [
                'method_code' => $payment->getMethodCode(),
                'method_title' => $payment->getMethodTitle(),
                'amount' => $payment->getAmount(),
                'amount_formatted' => $this->formatMoney($payment->getAmount(), $storeId, $currencyCode),
                'reference' => (string)($payment->getReference() ?? ''),
            ];
        }
        return [$payments, $changeDue];
    }

    private function formatOrderDate(OrderInterface $order): string
    {
        try {
            $createdAt = new \DateTime((string)($order->getCreatedAt() ?: 'now'), new \DateTimeZone('UTC'));
            return (string)$this->timezone->formatDateTime(
                $createdAt,
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            );
        } catch (\Exception $e) {
            return (string)$order->getCreatedAt();
        }
    }

    private function resolveStoreName(int $storeId): string
    {
        $configured = trim((string)$this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        if ($configured !== '') {
            return $configured;
        }
        try {
            return (string)$this->storeManager->getStore($storeId)->getFrontendName();
        } catch (NoSuchEntityException $e) {
            return '';
        }
    }

    private function resolveStoreAddress(int $storeId): string
    {
        $lines = [];
        foreach (['street_line1', 'street_line2'] as $field) {
            $value = trim((string)$this->scopeConfig->getValue(
                'general/store_information/' . $field,
                ScopeInterface::SCOPE_STORE,
                $storeId
            ));
            if ($value !== '') {
                $lines[] = $value;
            }
        }
        $cityLine = trim(implode(' ', array_filter([
            trim((string)$this->scopeConfig->getValue(
                'general/store_information/postcode',
                ScopeInterface::SCOPE_STORE,
                $storeId
            )),
            trim((string)$this->scopeConfig->getValue(
                'general/store_information/city',
                ScopeInterface::SCOPE_STORE,
                $storeId
            )),
        ])));
        if ($cityLine !== '') {
            $lines[] = $cityLine;
        }
        $phone = trim((string)$this->scopeConfig->getValue(
            'general/store_information/phone',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        if ($phone !== '') {
            $lines[] = $phone;
        }
        return implode("\n", $lines);
    }

    private function resolveLogoUrl(int $storeId): string
    {
        $logo = (string)($this->config->getReceiptLogo($storeId) ?? '');
        if ($logo === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $logo)) {
            return $logo;
        }
        try {
            $mediaBase = $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        } catch (NoSuchEntityException $e) {
            return '';
        }
        return rtrim($mediaBase, '/') . '/' . ltrim($logo, '/');
    }

    private function formatMoney(float $amount, int $storeId, string $currencyCode): string
    {
        return (string)$this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $storeId,
            $currencyCode !== '' ? $currencyCode : null
        );
    }

    private function formatQty(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.00001) {
            return (string)(int)round($qty);
        }
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }
}
