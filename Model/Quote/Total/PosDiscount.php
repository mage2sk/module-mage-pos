<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\Quote\Total;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;

class PosDiscount extends AbstractTotal
{
    public const COLLECTOR_CODE = 'pos_discount';

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';

    public const QUOTE_FIELD_TYPE = 'panth_pos_discount_type';
    public const QUOTE_FIELD_VALUE = 'panth_pos_discount_value';

    public function __construct()
    {
        $this->setCode(self::COLLECTOR_CODE);
    }

    public function collect(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ) {
        parent::collect($quote, $shippingAssignment, $total);

        if (count($shippingAssignment->getItems()) === 0) {
            return $this;
        }

        $type = (string)$quote->getData(self::QUOTE_FIELD_TYPE);
        $value = (float)$quote->getData(self::QUOTE_FIELD_VALUE);
        if ($value <= 0 || !in_array($type, [self::TYPE_PERCENT, self::TYPE_FIXED], true)) {
            return $this;
        }

        $address = $shippingAssignment->getShipping()->getAddress();
        $ruleDiscount = $this->getSalesRuleDiscount($address, false);
        $baseRuleDiscount = $this->getSalesRuleDiscount($address, true);

        $subtotal = max(0.0, (float)$total->getTotalAmount('subtotal') - $ruleDiscount);
        $baseSubtotal = max(0.0, (float)$total->getBaseTotalAmount('subtotal') - $baseRuleDiscount);

        if ($type === self::TYPE_PERCENT) {
            $percent = min($value, 100.0);
            $amount = $subtotal * $percent / 100;
            $baseAmount = $baseSubtotal * $percent / 100;
        } else {
            $amount = min($value, $subtotal);
            $baseAmount = min($value, $baseSubtotal);
        }

        $amount = round($amount, 4);
        $baseAmount = round($baseAmount, 4);
        if ($amount <= 0 && $baseAmount <= 0) {
            return $this;
        }

        $total->setTotalAmount(self::COLLECTOR_CODE, -$amount);
        $total->setBaseTotalAmount(self::COLLECTOR_CODE, -$baseAmount);

        $label = (string)$this->getLabel();
        $description = (string)$total->getDiscountDescription();
        if ($description === '') {
            $total->setDiscountDescription($label);
        } elseif (strpos($description, $label) === false) {
            $total->setDiscountDescription($description . ', ' . $label);
        }

        return $this;
    }

    private function getSalesRuleDiscount(?Address $address, bool $base): float
    {
        if (!$address instanceof Address) {
            return 0.0;
        }

        $amount = $base ? $address->getBaseDiscountAmount() : $address->getDiscountAmount();

        return abs((float)$amount);
    }

    public function fetch(Quote $quote, Total $total)
    {
        $amount = (float)$total->getTotalAmount(self::COLLECTOR_CODE);
        if (abs($amount) < 0.00001) {
            $amount = (float)$total->getData(self::COLLECTOR_CODE . '_amount');
        }

        if (abs($amount) < 0.00001) {
            return null;
        }

        return [
            'code' => $this->getCode(),
            'title' => $this->getLabel(),
            'value' => $amount,
        ];
    }

    public function getLabel()
    {
        return __('POS Discount');
    }
}
