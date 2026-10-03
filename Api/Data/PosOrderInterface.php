<?php
declare(strict_types=1);

namespace Panth\MagePos\Api\Data;

/**
 * POS order data interface.
 *
 * Represents a row in `panth_pos_order` - the POS-side metadata attached to a
 * Magento sales order (register, session, cashier, receipt number, offline
 * sync dedupe UUID).
 */
interface PosOrderInterface
{
    public const POS_ORDER_ID    = 'pos_order_id';
    public const ORDER_ID        = 'order_id';
    public const SESSION_ID      = 'session_id';
    public const REGISTER_ID     = 'register_id';
    public const POS_USER_ID     = 'pos_user_id';
    public const RECEIPT_NUMBER  = 'receipt_number';
    public const RECEIPT_TOKEN   = 'receipt_token';
    public const CLIENT_UUID     = 'client_uuid';
    public const IS_OFFLINE_SYNC = 'is_offline_sync';
    public const CREATED_AT      = 'created_at';

    /**
     * Get POS order id.
     *
     * @return int|null
     */
    public function getPosOrderId(): ?int;

    /**
     * Set POS order id.
     *
     * @param int $posOrderId
     * @return $this
     */
    public function setPosOrderId(int $posOrderId): self;

    /**
     * Get Magento sales order entity id.
     *
     * @return int
     */
    public function getOrderId(): int;

    /**
     * Set Magento sales order entity id.
     *
     * @param int $orderId
     * @return $this
     */
    public function setOrderId(int $orderId): self;

    /**
     * Get POS session id.
     *
     * @return int
     */
    public function getSessionId(): int;

    /**
     * Set POS session id.
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
    public function getRegisterId(): int;

    /**
     * Set register id.
     *
     * @param int $registerId
     * @return $this
     */
    public function setRegisterId(int $registerId): self;

    /**
     * Get id of the POS user (cashier) who placed the order.
     *
     * @return int
     */
    public function getPosUserId(): int;

    /**
     * Set id of the POS user (cashier) who placed the order.
     *
     * @param int $posUserId
     * @return $this
     */
    public function setPosUserId(int $posUserId): self;

    /**
     * Get receipt number ({register_code}-{session_id}-{seq}).
     *
     * @return string
     */
    public function getReceiptNumber(): string;

    /**
     * Set receipt number ({register_code}-{session_id}-{seq}).
     *
     * @param string $receiptNumber
     * @return $this
     */
    public function setReceiptNumber(string $receiptNumber): self;

    /**
     * Get the cryptographically random receipt access token.
     *
     * Receipt access is gated on a hash_equals match against this token; the
     * guessable receipt_number is never accepted as an access credential.
     *
     * @return string|null
     */
    public function getReceiptToken(): ?string;

    /**
     * Set the cryptographically random receipt access token.
     *
     * @param string|null $receiptToken
     * @return $this
     */
    public function setReceiptToken(?string $receiptToken): self;

    /**
     * Get client UUID used for offline sync deduplication.
     *
     * @return string|null
     */
    public function getClientUuid(): ?string;

    /**
     * Set client UUID used for offline sync deduplication.
     *
     * @param string|null $clientUuid
     * @return $this
     */
    public function setClientUuid(?string $clientUuid): self;

    /**
     * Get offline-sync flag (1 = order was queued offline and synced later).
     *
     * @return int
     */
    public function getIsOfflineSync(): int;

    /**
     * Set offline-sync flag (1 = order was queued offline and synced later).
     *
     * @param int $isOfflineSync
     * @return $this
     */
    public function setIsOfflineSync(int $isOfflineSync): self;

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
