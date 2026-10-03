<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Model\ResourceModel\Session as SessionResource;

class Session extends AbstractModel implements SessionInterface
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $_idFieldName = 'session_id';

    protected $_eventPrefix = 'panth_pos_session';

    protected function _construct(): void
    {
        $this->_init(SessionResource::class);
    }

    public function getSessionId(): ?int
    {
        $value = $this->getData(SessionInterface::SESSION_ID);
        return $value === null ? null : (int)$value;
    }

    public function setSessionId(int $sessionId): self
    {
        return $this->setData(SessionInterface::SESSION_ID, $sessionId);
    }

    public function getRegisterId(): ?int
    {
        $value = $this->getData(SessionInterface::REGISTER_ID);
        return $value === null ? null : (int)$value;
    }

    public function setRegisterId(int $registerId): self
    {
        return $this->setData(SessionInterface::REGISTER_ID, $registerId);
    }

    public function getUserId(): ?int
    {
        $value = $this->getData(SessionInterface::USER_ID);
        return $value === null ? null : (int)$value;
    }

    public function setUserId(int $userId): self
    {
        return $this->setData(SessionInterface::USER_ID, $userId);
    }

    public function getStatus(): ?string
    {
        $value = $this->getData(SessionInterface::STATUS);
        return $value === null ? null : (string)$value;
    }

    public function setStatus(string $status): self
    {
        return $this->setData(SessionInterface::STATUS, $status);
    }

    public function getOpeningFloat(): ?float
    {
        $value = $this->getData(SessionInterface::OPENING_FLOAT);
        return $value === null ? null : (float)$value;
    }

    public function setOpeningFloat(float $openingFloat): self
    {
        return $this->setData(SessionInterface::OPENING_FLOAT, $openingFloat);
    }

    public function getExpectedCash(): ?float
    {
        $value = $this->getData(SessionInterface::EXPECTED_CASH);
        return $value === null ? null : (float)$value;
    }

    public function setExpectedCash(?float $expectedCash): self
    {
        return $this->setData(SessionInterface::EXPECTED_CASH, $expectedCash);
    }

    public function getCountedCash(): ?float
    {
        $value = $this->getData(SessionInterface::COUNTED_CASH);
        return $value === null ? null : (float)$value;
    }

    public function setCountedCash(?float $countedCash): self
    {
        return $this->setData(SessionInterface::COUNTED_CASH, $countedCash);
    }

    public function getOverShort(): ?float
    {
        $value = $this->getData(SessionInterface::OVER_SHORT);
        return $value === null ? null : (float)$value;
    }

    public function setOverShort(?float $overShort): self
    {
        return $this->setData(SessionInterface::OVER_SHORT, $overShort);
    }

    public function getTotalsJson(): ?string
    {
        $value = $this->getData(SessionInterface::TOTALS_JSON);
        return $value === null ? null : (string)$value;
    }

    public function setTotalsJson(?string $totalsJson): self
    {
        return $this->setData(SessionInterface::TOTALS_JSON, $totalsJson);
    }

    public function getOpenedAt(): ?string
    {
        $value = $this->getData(SessionInterface::OPENED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setOpenedAt(string $openedAt): self
    {
        return $this->setData(SessionInterface::OPENED_AT, $openedAt);
    }

    public function getClosedAt(): ?string
    {
        $value = $this->getData(SessionInterface::CLOSED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setClosedAt(?string $closedAt): self
    {
        return $this->setData(SessionInterface::CLOSED_AT, $closedAt);
    }

    public function getNote(): ?string
    {
        $value = $this->getData(SessionInterface::NOTE);
        return $value === null ? null : (string)$value;
    }

    public function setNote(?string $note): self
    {
        return $this->setData(SessionInterface::NOTE, $note);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(SessionInterface::CREATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setCreatedAt(string $createdAt): self
    {
        return $this->setData(SessionInterface::CREATED_AT, $createdAt);
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(SessionInterface::UPDATED_AT);
        return $value === null ? null : (string)$value;
    }

    public function setUpdatedAt(string $updatedAt): self
    {
        return $this->setData(SessionInterface::UPDATED_AT, $updatedAt);
    }
}
