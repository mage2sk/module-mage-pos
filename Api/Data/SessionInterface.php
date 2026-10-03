<?php
declare(strict_types=1);

namespace Panth\MagePos\Api\Data;

/**
 * POS register session data interface.
 *
 * Represents a row in `panth_pos_session` - one cash-drawer shift on a
 * register, from opening float to Z-report close (counted cash, over/short
 * and a JSON totals snapshot).
 */
interface SessionInterface
{
    public const SESSION_ID    = 'session_id';
    public const REGISTER_ID   = 'register_id';
    public const USER_ID       = 'user_id';
    public const STATUS        = 'status';
    public const OPENING_FLOAT = 'opening_float';
    public const EXPECTED_CASH = 'expected_cash';
    public const COUNTED_CASH  = 'counted_cash';
    public const OVER_SHORT    = 'over_short';
    public const TOTALS_JSON   = 'totals_json';
    public const OPENED_AT     = 'opened_at';
    public const CLOSED_AT     = 'closed_at';
    public const NOTE          = 'note';
    public const CREATED_AT    = 'created_at';
    public const UPDATED_AT    = 'updated_at';

    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    /**
     * Get session id.
     *
     * @return int|null
     */
    public function getSessionId(): ?int;

    /**
     * Set session id.
     *
     * @param int $sessionId
     * @return $this
     */
    public function setSessionId(int $sessionId): self;

    /**
     * Get register id.
     *
     * @return int
     */
    public function getRegisterId(): ?int;

    /**
     * Set register id.
     *
     * @param int $registerId
     * @return $this
     */
    public function setRegisterId(int $registerId): self;

    /**
     * Get id of the POS user who opened the session.
     *
     * @return int
     */
    public function getUserId(): ?int;

    /**
     * Set id of the POS user who opened the session.
     *
     * @param int $userId
     * @return $this
     */
    public function setUserId(int $userId): self;

    /**
     * Get status (open|closed).
     *
     * @return string
     */
    public function getStatus(): ?string;

    /**
     * Set status (open|closed).
     *
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * Get opening float amount.
     *
     * @return float
     */
    public function getOpeningFloat(): ?float;

    /**
     * Set opening float amount.
     *
     * @param float $openingFloat
     * @return $this
     */
    public function setOpeningFloat(float $openingFloat): self;

    /**
     * Get expected cash at close.
     *
     * @return float|null
     */
    public function getExpectedCash(): ?float;

    /**
     * Set expected cash at close.
     *
     * @param float|null $expectedCash
     * @return $this
     */
    public function setExpectedCash(?float $expectedCash): self;

    /**
     * Get counted cash at close.
     *
     * @return float|null
     */
    public function getCountedCash(): ?float;

    /**
     * Set counted cash at close.
     *
     * @param float|null $countedCash
     * @return $this
     */
    public function setCountedCash(?float $countedCash): self;

    /**
     * Get over/short amount (counted - expected).
     *
     * @return float|null
     */
    public function getOverShort(): ?float;

    /**
     * Set over/short amount (counted - expected).
     *
     * @param float|null $overShort
     * @return $this
     */
    public function setOverShort(?float $overShort): self;

    /**
     * Get Z-report totals snapshot JSON.
     *
     * @return string|null
     */
    public function getTotalsJson(): ?string;

    /**
     * Set Z-report totals snapshot JSON.
     *
     * @param string|null $totalsJson
     * @return $this
     */
    public function setTotalsJson(?string $totalsJson): self;

    /**
     * Get opened-at timestamp.
     *
     * @return string|null
     */
    public function getOpenedAt(): ?string;

    /**
     * Set opened-at timestamp.
     *
     * @param string $openedAt
     * @return $this
     */
    public function setOpenedAt(string $openedAt): self;

    /**
     * Get closed-at timestamp.
     *
     * @return string|null
     */
    public function getClosedAt(): ?string;

    /**
     * Set closed-at timestamp.
     *
     * @param string|null $closedAt
     * @return $this
     */
    public function setClosedAt(?string $closedAt): self;

    /**
     * Get session note.
     *
     * @return string|null
     */
    public function getNote(): ?string;

    /**
     * Set session note.
     *
     * @param string|null $note
     * @return $this
     */
    public function setNote(?string $note): self;

    /**
     * Get creation timestamp.
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * Set creation timestamp.
     *
     * @param string $createdAt
     * @return $this
     */
    public function setCreatedAt(string $createdAt): self;

    /**
     * Get last update timestamp.
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string;

    /**
     * Set last update timestamp.
     *
     * @param string $updatedAt
     * @return $this
     */
    public function setUpdatedAt(string $updatedAt): self;
}
