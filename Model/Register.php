<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\MagePos\Api\Data\RegisterInterface;

class Register extends AbstractModel implements RegisterInterface
{
    public const CACHE_TAG = 'panth_pos_register';

    protected $_cacheTag = self::CACHE_TAG;

    protected $_eventPrefix = 'panth_pos_register';

    protected $_eventObject = 'register';

    protected $_idFieldName = 'register_id';

    protected function _construct(): void
    {
        $this->_init(\Panth\MagePos\Model\ResourceModel\Register::class);
    }

    public function getIdentities(): array
    {
        return [self::CACHE_TAG . '_' . (int) $this->getId()];
    }

    public function getRegisterId(): ?int
    {
        $value = $this->getData(self::REGISTER_ID);
        return $value === null ? null : (int) $value;
    }

    public function setRegisterId(int $registerId): self
    {
        $this->setData(self::REGISTER_ID, $registerId);
        return $this;
    }

    public function getName(): string
    {
        return (string) $this->getData(self::NAME);
    }

    public function setName(string $name): self
    {
        $this->setData(self::NAME, $name);
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

    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    public function setStoreId(int $storeId): self
    {
        $this->setData(self::STORE_ID, $storeId);
        return $this;
    }

    public function getStatus(): int
    {
        return (int) $this->getData(self::STATUS);
    }

    public function setStatus(int $status): self
    {
        $this->setData(self::STATUS, $status);
        return $this;
    }

    public function getReceiptHeader(): ?string
    {
        $value = $this->getData(self::RECEIPT_HEADER);
        return $value === null ? null : (string) $value;
    }

    public function setReceiptHeader(?string $receiptHeader): self
    {
        $this->setData(self::RECEIPT_HEADER, $receiptHeader);
        return $this;
    }

    public function getReceiptFooter(): ?string
    {
        $value = $this->getData(self::RECEIPT_FOOTER);
        return $value === null ? null : (string) $value;
    }

    public function setReceiptFooter(?string $receiptFooter): self
    {
        $this->setData(self::RECEIPT_FOOTER, $receiptFooter);
        return $this;
    }

    public function getSourceCode(): ?string
    {
        $value = $this->getData(self::SOURCE_CODE);
        return $value === null ? null : (string) $value;
    }

    public function setSourceCode(?string $sourceCode): self
    {
        $this->setData(self::SOURCE_CODE, $sourceCode);
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
