<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\MagePos\Api\Data\CashMovementInterface;
use Panth\MagePos\Model\ResourceModel\CashMovement as CashMovementResource;

class CashMovement extends AbstractModel implements CashMovementInterface
{
    public const TYPE_FLOAT = 'float';
    public const TYPE_SALE = 'sale';
    public const TYPE_REFUND = 'refund';
    public const TYPE_IN = 'in';
    public const TYPE_OUT = 'out';
    public const TYPE_CLOSE = 'close';

    protected $_idFieldName = 'movement_id';

    protected $_eventPrefix = 'panth_pos_cash_movement';

    protected function _construct(): void
    {
        $this->_init(CashMovementResource::class);
    }

    public function getMovementId(): ?int
    {
        $value = $this->getData(CashMovementInterface::MOVEMENT_ID);
        return $value === null ? null : (int)$value;
    }

    public function setMovementId(int $movementId): self
    {
        return $this->setData(CashMovementInterface::MOVEMENT_ID, $movementId);
    }

    public function getSessionId(): ?int
    {
        $value = $this->getData(CashMovementInterface::SESSION_ID);
        return $value === null ? null : (int)$value;
    }

    public function setSessionId(int $sessionId): self
    {
        return $this->setData(CashMovementInterface::SESSION_ID, $sessionId);
    }

    public function getUserId(): ?int
    {
        $value = $this->getData(CashMovementInterface::USER_ID);
        return $value === null ? null : (int)$value;
    }

    public function setUserId(int $userId): self
    {
        return $this->setData(CashMovementInterface::USER_ID, $userId);
    }

    public function getType(): ?string
    {
        $value = $this->getData(CashMovementInterface::TYPE);
        return $value === null ? null : (string)$value;
    }

    public function setType(string $type): self
    {
        return $this->setData(CashMovementInterface::TYPE, $type);
    }

    public function getAmount(): ?float
    {
        $value = $this->getData(CashMovementInterface::AMOUNT);
        return $value === null ? null : (float)$value;
    }

    public function setAmount(float $amount): self
    {
        return $this->setData(CashMovementInterface::AMOUNT, $amount);
    }

    public function getReason(): ?string
    {
        $value = $this->getData(CashMovementInterface::REASON);
        return $value === null ? null : (string)$value;
    }

    public function setReason(?string $reason): self
    {
        return $this->setData(CashMovementInterface::REASON, $reason);
    }

    public function getOrderId(): ?int
    {
        $value = $this->getData(CashMovementInterface::ORDER_ID);
        return $value === null ? null : (int)$value;
    }

    public function setOrderId(?int $orderId): self
    {
        return $this->setData(CashMovementInterface::ORDER_ID, $orderId);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(CashMovementInterface::CREATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setCreatedAt(string $createdAt): self
    {
        return $this->setData(CashMovementInterface::CREATED_AT, $createdAt);
    }
}
