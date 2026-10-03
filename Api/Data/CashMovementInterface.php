<?php
declare(strict_types=1);

namespace Panth\MagePos\Api\Data;

/**
 * Cash drawer movement data interface.
 *
 * Represents a row in `panth_pos_cash_movement` - an append-only ledger of
 * drawer activity within a session. `amount` is stored SIGNED:
 * float/sale/in positive, refund/out negative.
 */
interface CashMovementInterface
{
    public const MOVEMENT_ID = 'movement_id';
    public const SESSION_ID  = 'session_id';
    public const USER_ID     = 'user_id';
    public const TYPE        = 'type';
    public const AMOUNT      = 'amount';
    public const REASON      = 'reason';
    public const ORDER_ID    = 'order_id';
    public const CREATED_AT  = 'created_at';

    public const TYPE_FLOAT  = 'float';
    public const TYPE_SALE   = 'sale';
    public const TYPE_REFUND = 'refund';
    public const TYPE_IN     = 'in';
    public const TYPE_OUT    = 'out';
    public const TYPE_CLOSE  = 'close';

    /**
     * Get movement id.
     *
     * @return int|null
     */
    public function getMovementId(): ?int;

    /**
     * Set movement id.
     *
     * @param int $movementId
     * @return $this
     */
    public function setMovementId(int $movementId): self;

    /**
     * Get session id.
     *
     * @return int
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
     * Get id of the POS user who created the movement.
     *
     * @return int
     */
    public function getUserId(): ?int;

    /**
     * Set id of the POS user who created the movement.
     *
     * @param int $userId
     * @return $this
     */
    public function setUserId(int $userId): self;

    /**
     * Get movement type (float|sale|refund|in|out|close).
     *
     * @return string
     */
    public function getType(): ?string;

    /**
     * Set movement type (float|sale|refund|in|out|close).
     *
     * @param string $type
     * @return $this
     */
    public function setType(string $type): self;

    /**
     * Get signed amount.
     *
     * @return float
     */
    public function getAmount(): ?float;

    /**
     * Set signed amount.
     *
     * @param float $amount
     * @return $this
     */
    public function setAmount(float $amount): self;

    /**
     * Get reason text.
     *
     * @return string|null
     */
    public function getReason(): ?string;

    /**
     * Set reason text.
     *
     * @param string|null $reason
     * @return $this
     */
    public function setReason(?string $reason): self;

    /**
     * Get related sales order id.
     *
     * @return int|null
     */
    public function getOrderId(): ?int;

    /**
     * Set related sales order id.
     *
     * @param int|null $orderId
     * @return $this
     */
    public function setOrderId(?int $orderId): self;

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
}
