<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\MagePos\Api\Data\HoldInterface;

class Hold extends AbstractModel implements HoldInterface
{
    public const CACHE_TAG = 'panth_pos_hold';

    protected $_cacheTag = self::CACHE_TAG;

    protected $_eventPrefix = 'panth_pos_hold';

    protected $_eventObject = 'hold';

    protected $_idFieldName = 'hold_id';

    protected function _construct(): void
    {
        $this->_init(\Panth\MagePos\Model\ResourceModel\Hold::class);
    }

    public function getIdentities(): array
    {
        return [self::CACHE_TAG . '_' . (int) $this->getId()];
    }

    public function getHoldId(): ?int
    {
        $value = $this->getData(self::HOLD_ID);
        return $value === null ? null : (int) $value;
    }

    public function setHoldId(int $holdId): self
    {
        $this->setData(self::HOLD_ID, $holdId);
        return $this;
    }

    public function getRegisterId(): int
    {
        return (int) $this->getData(self::REGISTER_ID);
    }

    public function setRegisterId(int $registerId): self
    {
        $this->setData(self::REGISTER_ID, $registerId);
        return $this;
    }

    public function getUserId(): int
    {
        return (int) $this->getData(self::USER_ID);
    }

    public function setUserId(int $userId): self
    {
        $this->setData(self::USER_ID, $userId);
        return $this;
    }

    public function getLabel(): string
    {
        return (string) $this->getData(self::LABEL);
    }

    public function setLabel(string $label): self
    {
        $this->setData(self::LABEL, $label);
        return $this;
    }

    public function getCustomerId(): ?int
    {
        $value = $this->getData(self::CUSTOMER_ID);
        return $value === null ? null : (int) $value;
    }

    public function setCustomerId(?int $customerId): self
    {
        $this->setData(self::CUSTOMER_ID, $customerId);
        return $this;
    }

    public function getCartJson(): string
    {
        return (string) $this->getData(self::CART_JSON);
    }

    public function setCartJson(string $cartJson): self
    {
        $this->setData(self::CART_JSON, $cartJson);
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
