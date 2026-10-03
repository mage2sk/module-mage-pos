<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\MagePos\Api\Data\PosOrderPaymentInterface;

class PosOrderPayment extends AbstractModel implements PosOrderPaymentInterface
{
    protected $_eventPrefix = 'panth_pos_order_payment';

    protected $_eventObject = 'pos_order_payment';

    protected $_idFieldName = 'payment_id';

    protected function _construct(): void
    {
        $this->_init(\Panth\MagePos\Model\ResourceModel\PosOrderPayment::class);
    }

    public function getPaymentId(): ?int
    {
        $value = $this->getData(self::PAYMENT_ID);
        return $value === null ? null : (int) $value;
    }

    public function setPaymentId(int $paymentId): self
    {
        $this->setData(self::PAYMENT_ID, $paymentId);
        return $this;
    }

    public function getPosOrderId(): int
    {
        return (int) $this->getData(self::POS_ORDER_ID);
    }

    public function setPosOrderId(int $posOrderId): self
    {
        $this->setData(self::POS_ORDER_ID, $posOrderId);
        return $this;
    }

    public function getMethodCode(): string
    {
        return (string) $this->getData(self::METHOD_CODE);
    }

    public function setMethodCode(string $methodCode): self
    {
        $this->setData(self::METHOD_CODE, $methodCode);
        return $this;
    }

    public function getMethodTitle(): string
    {
        return (string) $this->getData(self::METHOD_TITLE);
    }

    public function setMethodTitle(string $methodTitle): self
    {
        $this->setData(self::METHOD_TITLE, $methodTitle);
        return $this;
    }

    public function getAmount(): float
    {
        return (float) $this->getData(self::AMOUNT);
    }

    public function setAmount(float $amount): self
    {
        $this->setData(self::AMOUNT, $amount);
        return $this;
    }

    public function getReference(): ?string
    {
        $value = $this->getData(self::REFERENCE);
        return $value === null ? null : (string) $value;
    }

    public function setReference(?string $reference): self
    {
        $this->setData(self::REFERENCE, $reference);
        return $this;
    }

    public function getIsChange(): int
    {
        return (int) $this->getData(self::IS_CHANGE);
    }

    public function setIsChange(int $isChange): self
    {
        $this->setData(self::IS_CHANGE, $isChange);
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setCreatedAt(?string $createdAt): self
    {
        $this->setData(self::CREATED_AT, $createdAt);
        return $this;
    }
}
