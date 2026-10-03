<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\Payment;

use Panth\MagePos\Api\PaymentProcessorInterface;

class ProcessorPool
{
    public function __construct(
        private readonly array $processors = []
    ) {
        foreach ($this->processors as $name => $processor) {
            if (!$processor instanceof PaymentProcessorInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'POS payment processor "%s" must implement %s.',
                    (string)$name,
                    PaymentProcessorInterface::class
                ));
            }
        }
    }

    public function getForMethod(string $code): ?PaymentProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($code)) {
                return $processor;
            }
        }

        return null;
    }
}
