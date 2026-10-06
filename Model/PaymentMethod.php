<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\MagePos\Api\Data\PaymentMethodInterface;

class PaymentMethod extends AbstractModel implements PaymentMethodInterface
{
    public const CACHE_TAG = 'panth_pos_payment_method';

    protected $_cacheTag = self::CACHE_TAG;

    protected $_eventPrefix = 'panth_pos_payment_method';

    protected $_eventObject = 'payment_method';

    protected $_idFieldName = 'method_id';

    protected function _construct(): void
    {
        $this->_init(\Panth\MagePos\Model\ResourceModel\PaymentMethod::class);
    }

    public function getIdentities(): array
    {
        return [self::CACHE_TAG . '_' . (int) $this->getId()];
    }

    public function getMethodId(): ?int
    {
        $value = $this->getData(self::METHOD_ID);
        return $value === null ? null : (int) $value;
    }

    public function setMethodId(int $methodId): self
    {
        $this->setData(self::METHOD_ID, $methodId);
        return $this;
    }

    public function getCode(): string
    {
        return (string) $this->getData(self::CODE);
    }

    public function setCode(string $code): self
    {
        $this->setData(self::CODE, $code);
        return $this;
    }

    public function getTitle(): string
    {
        return (string) $this->getData(self::TITLE);
    }

    public function setTitle(string $title): self
    {
        $this->setData(self::TITLE, $title);
        return $this;
    }

    public function getType(): string
    {
        return (string) $this->getData(self::TYPE);
    }

    public function setType(string $type): self
    {
        $this->setData(self::TYPE, $type);
        return $this;
    }

    public function getIsActive(): int
    {
        return (int) $this->getData(self::IS_ACTIVE);
    }

    public function setIsActive(int $isActive): self
    {
        $this->setData(self::IS_ACTIVE, $isActive);
        return $this;
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData(self::SORT_ORDER);
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->setData(self::SORT_ORDER, $sortOrder);
        return $this;
    }

    public function getIcon(): ?string
    {
        $value = $this->getData(self::ICON);
        return $value === null ? null : (string) $value;
    }

    public function setIcon(?string $icon): self
    {
        $this->setData(self::ICON, $icon);
        return $this;
    }

    public function getRequiresReference(): int
    {
        return (int) $this->getData(self::REQUIRES_REFERENCE);
    }

    public function setRequiresReference(int $requiresReference): self
    {
        $this->setData(self::REQUIRES_REFERENCE, $requiresReference);
        return $this;
    }

    public function getInstructions(): ?string
    {
        $value = $this->getData(self::INSTRUCTIONS);
        return $value === null ? null : (string) $value;
    }

    public function setInstructions(?string $instructions): self
    {
        $this->setData(self::INSTRUCTIONS, $instructions);
        return $this;
    }

    public function getOpenDrawer(): int
    {
        return (int) $this->getData(self::OPEN_DRAWER);
    }

    public function setOpenDrawer(int $openDrawer): self
    {
        $this->setData(self::OPEN_DRAWER, $openDrawer);
        return $this;
    }

    public function getPaymentUrlTemplate(): ?string
    {
        $value = $this->getData(self::PAYMENT_URL_TEMPLATE);
        return $value === null ? null : (string) $value;
    }

    public function setPaymentUrlTemplate(?string $paymentUrlTemplate): self
    {
        $this->setData(self::PAYMENT_URL_TEMPLATE, $paymentUrlTemplate);
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

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setUpdatedAt(?string $updatedAt): self
    {
        $this->setData(self::UPDATED_AT, $updatedAt);
        return $this;
    }
}
