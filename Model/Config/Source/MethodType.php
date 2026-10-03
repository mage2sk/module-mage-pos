<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\MagePos\Api\Data\PaymentMethodInterface;

class MethodType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            [
                'value' => PaymentMethodInterface::TYPE_CASH,
                'label' => __('Cash (change calculation, opens drawer)'),
            ],
            [
                'value' => PaymentMethodInterface::TYPE_OFFLINE,
                'label' => __('Offline (card terminal, check, bank transfer, ...)'),
            ],
            [
                'value' => PaymentMethodInterface::TYPE_ONLINE,
                'label' => __('Online (payment link / QR, order stays pending)'),
            ],
        ];
    }
}
