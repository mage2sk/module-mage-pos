<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\MagePos\Api\Data\PosOrderInterface;

class PosOrder extends AbstractModel implements PosOrderInterface
{
    protected $_eventPrefix = 'panth_pos_order';

    protected $_eventObject = 'pos_order';

    protected $_idFieldName = 'pos_order_id';

    protected function _construct(): void
    {
        $this->_init(\Panth\MagePos\Model\ResourceModel\PosOrder::class);
    }

    public function getPosOrderId(): ?int
    {
        $value = $this->getData(self::POS_ORDER_ID);
        return $value === null ? null : (int) $value;
    }

    public function setPosOrderId(int $posOrderId): self
    {
        $this->setData(self::POS_ORDER_ID, $posOrderId);
        return $this;
    }

    public function getOrderId(): int
    {
        return (int) $this->getData(self::ORDER_ID);
    }

    public function setOrderId(int $orderId): self
    {
        $this->setData(self::ORDER_ID, $orderId);
        return $this;
    }

    public function getSessionId(): int
    {
        return (int) $this->getData(self::SESSION_ID);
    }

    public function setSessionId(int $sessionId): self
    {
        $this->setData(self::SESSION_ID, $sessionId);
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

    public function getPosUserId(): int
    {
        return (int) $this->getData(self::POS_USER_ID);
    }

    public function setPosUserId(int $posUserId): self
    {
        $this->setData(self::POS_USER_ID, $posUserId);
        return $this;
    }

    public function getReceiptNumber(): string
    {
        return (string) $this->getData(self::RECEIPT_NUMBER);
    }

    public function setReceiptNumber(string $receiptNumber): self
    {
        $this->setData(self::RECEIPT_NUMBER, $receiptNumber);
        return $this;
    }

    public function getReceiptToken(): ?string
    {
        $value = $this->getData(self::RECEIPT_TOKEN);
        return $value === null ? null : (string) $value;
    }

    public function setReceiptToken(?string $receiptToken): self
    {
        $this->setData(self::RECEIPT_TOKEN, $receiptToken);
        return $this;
    }

    public function getClientUuid(): ?string
    {
        $value = $this->getData(self::CLIENT_UUID);
        return $value === null ? null : (string) $value;
    }

    public function setClientUuid(?string $clientUuid): self
    {
        $this->setData(self::CLIENT_UUID, $clientUuid);
        return $this;
    }

    public function getIsOfflineSync(): int
    {
        return (int) $this->getData(self::IS_OFFLINE_SYNC);
    }

    public function setIsOfflineSync(int $isOfflineSync): self
    {
        $this->setData(self::IS_OFFLINE_SYNC, $isOfflineSync);
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
