<?php
declare(strict_types=1);

namespace Panth\MagePos\Api;

/**
 * Extension point for real online payment gateway integrations at the POS.
 *
 * Implementations are registered in di.xml via the
 * `Panth\MagePos\Model\Payment\ProcessorPool` constructor argument
 * `array $processors = []` and resolved per POS payment method code.
 */
interface PaymentProcessorInterface
{
    /**
     * Whether this processor handles the given POS payment method code.
     *
     * @param string $methodCode
     * @return bool
     */
    public function supports(string $methodCode): bool;

    /**
     * Process one (online) payment row for the given order.
     *
     * $paymentRow is the POS payment row: {method_code, amount, reference?}.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $paymentRow
     * @return array ['status' => 'paid'|'pending', 'reference' => string|null, 'redirect_url' => string|null]
     */
    public function process(\Magento\Sales\Api\Data\OrderInterface $order, array $paymentRow): array;
}
