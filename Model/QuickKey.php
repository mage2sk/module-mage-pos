<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\MagePos\Api\Data\QuickKeyInterface;

class QuickKey extends AbstractModel implements QuickKeyInterface
{
    public const CACHE_TAG = 'panth_pos_quick_key';

    protected $_cacheTag = self::CACHE_TAG;

    protected $_eventPrefix = 'panth_pos_quick_key';

    protected $_eventObject = 'quick_key';

    protected $_idFieldName = 'quick_key_id';

    protected function _construct(): void
    {
        $this->_init(\Panth\MagePos\Model\ResourceModel\QuickKey::class);
    }

    public function getIdentities(): array
    {
        return [self::CACHE_TAG . '_' . (int) $this->getId()];
    }

    public function getQuickKeyId(): ?int
    {
        $value = $this->getData(self::QUICK_KEY_ID);
        return $value === null ? null : (int) $value;
    }

    public function setQuickKeyId(int $quickKeyId): self
    {
        $this->setData(self::QUICK_KEY_ID, $quickKeyId);
        return $this;
    }

    public function getRegisterId(): ?int
    {
        $value = $this->getData(self::REGISTER_ID);
        return $value === null ? null : (int) $value;
    }

    public function setRegisterId(?int $registerId): self
    {
        $this->setData(self::REGISTER_ID, $registerId);
        return $this;
    }

    public function getProductId(): int
    {
        return (int) $this->getData(self::PRODUCT_ID);
    }

    public function setProductId(int $productId): self
    {
        $this->setData(self::PRODUCT_ID, $productId);
        return $this;
    }

    public function getLabel(): ?string
    {
        $value = $this->getData(self::LABEL);
        return $value === null ? null : (string) $value;
    }

    public function setLabel(?string $label): self
    {
        $this->setData(self::LABEL, $label);
        return $this;
    }

    public function getColor(): ?string
    {
        $value = $this->getData(self::COLOR);
        return $value === null ? null : (string) $value;
    }

    public function setColor(?string $color): self
    {
        $this->setData(self::COLOR, $color);
        return $this;
    }

    public function getPosition(): int
    {
        return (int) $this->getData(self::POSITION);
    }

    public function setPosition(int $position): self
    {
        $this->setData(self::POSITION, $position);
        return $this;
    }

    public function getPage(): int
    {
        return (int) $this->getData(self::PAGE);
    }

    public function setPage(int $page): self
    {
        $this->setData(self::PAGE, $page);
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
