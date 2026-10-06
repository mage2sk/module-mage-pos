<?php
declare(strict_types=1);

namespace Panth\MagePos\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\MagePos\Model\ResourceModel\PaymentMethod as PaymentMethodResource;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\CollectionFactory as PaymentMethodCollectionFactory;
use Panth\MagePos\Service\CheckoutService;
use Psr\Log\LoggerInterface;

class FixPaymentLinkUrl implements DataPatchInterface
{
    public function __construct(
        private readonly PaymentMethodCollectionFactory $paymentMethodCollectionFactory,
        private readonly PaymentMethodResource $paymentMethodResource,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        try {
            $collection = $this->paymentMethodCollectionFactory->create();
            $collection->addFieldToFilter('payment_url_template', ['like' => '%example.com%']);

            foreach ($collection as $method) {
                $method->setPaymentUrlTemplate(CheckoutService::DEFAULT_PAYMENT_URL_TEMPLATE);
                $this->paymentMethodResource->save($method);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Panth_MagePos] FixPaymentLinkUrl data patch failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
        return $this;
    }
}
