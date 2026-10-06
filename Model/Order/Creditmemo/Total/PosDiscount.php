<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\Order\Creditmemo\Total;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;

class PosDiscount extends AbstractTotal
{
    private const EPSILON = 0.005;

    public function collect(Creditmemo $creditmemo)
    {
        parent::collect($creditmemo);

        $order = $creditmemo->getOrder();
        if (!$order instanceof Order) {
            return $this;
        }

        $manualDiscount = $this->orderPosManualDiscount($order);
        $baseManualDiscount = $this->baseOrderPosManualDiscount($order);
        if ($manualDiscount <= self::EPSILON && $baseManualDiscount <= self::EPSILON) {
            return $this;
        }

        $share = $this->refundShare($order, $creditmemo);
        if ($share <= 0.0) {
            return $this;
        }

        $this->applyShare(
            $creditmemo,
            'getGrandTotal',
            'setGrandTotal',
            $manualDiscount,
            $share
        );
        $this->applyShare(
            $creditmemo,
            'getBaseGrandTotal',
            'setBaseGrandTotal',
            $baseManualDiscount,
            $share
        );

        return $this;
    }

    private function applyShare(
        Creditmemo $creditmemo,
        string $getter,
        string $setter,
        float $manualDiscount,
        float $share
    ): void {
        if ($manualDiscount <= self::EPSILON) {
            return;
        }
        $current = (float)$creditmemo->{$getter}();
        $reduction = min(round($manualDiscount * $share, 2), $current);
        if ($reduction <= 0.0) {
            return;
        }
        $creditmemo->{$setter}(round($current - $reduction, 2));
    }

    private function refundShare(Order $order, Creditmemo $creditmemo): float
    {
        $orderSubtotal = (float)$order->getSubtotal();
        $cmSubtotal = (float)$creditmemo->getSubtotal();
        if ($orderSubtotal > self::EPSILON) {
            return max(0.0, min(1.0, $cmSubtotal / $orderSubtotal));
        }

        return 1.0;
    }

    private function storedPosDiscount(Order $order, string $key): ?float
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return null;
        }
        $info = $payment->getAdditionalInformation();
        if (!is_array($info) || !isset($info[$key]) || !is_numeric($info[$key])) {
            return null;
        }

        return max(0.0, round((float)$info[$key], 2));
    }

    private function orderPosManualDiscount(Order $order): float
    {
        $stored = $this->storedPosDiscount($order, 'pos_discount_amount');
        if ($stored !== null) {
            return $stored;
        }
        $residual = (float)$order->getSubtotal()
            + (float)$order->getTaxAmount()
            + (float)$order->getDiscountAmount()
            + (float)$order->getShippingAmount()
            + (float)$order->getShippingTaxAmount()
            - (float)$order->getGrandTotal();

        return $residual > self::EPSILON ? round($residual, 2) : 0.0;
    }

    private function baseOrderPosManualDiscount(Order $order): float
    {
        $stored = $this->storedPosDiscount($order, 'base_pos_discount_amount');
        if ($stored !== null) {
            return $stored;
        }
        $residual = (float)$order->getBaseSubtotal()
            + (float)$order->getBaseTaxAmount()
            + (float)$order->getBaseDiscountAmount()
            + (float)$order->getBaseShippingAmount()
            + (float)$order->getBaseShippingTaxAmount()
            - (float)$order->getBaseGrandTotal();

        return $residual > self::EPSILON ? round($residual, 2) : 0.0;
    }
}
