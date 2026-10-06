<?php
declare(strict_types=1);

namespace Panth\MagePos\Api\Data;

/**
 * Held cart data interface.
 *
 * Represents a row in `panth_pos_hold` - a parked/held POS cart (serialized
 * as JSON) that can be retrieved later on the same register.
 */
interface HoldInterface
{
    public const HOLD_ID     = 'hold_id';
    public const REGISTER_ID = 'register_id';
    public const USER_ID     = 'user_id';
    public const LABEL       = 'label';
    public const CUSTOMER_ID = 'customer_id';
    public const CART_JSON   = 'cart_json';
    public const CREATED_AT  = 'created_at';
    public const UPDATED_AT  = 'updated_at';

    /**
     * Get hold id.
     *
     * @return int|null
     */
    public function getHoldId(): ?int;

    /**
     * Set hold id.
     *
     * @param int $holdId
     * @return $this
     */
    public function setHoldId(int $holdId): self;

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
     * Get id of the POS user who held the cart.
     *
     * @return int
     */
    public function getUserId(): int;

    /**
     * Set id of the POS user who held the cart.
     *
     * @param int $userId
     * @return $this
     */
    public function setUserId(int $userId): self;

    /**
     * Get hold label.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Set hold label.
     *
     * @param string $label
     * @return $this
     */
    public function setLabel(string $label): self;

    /**
     * Get attached customer id.
     *
     * @return int|null
     */
    public function getCustomerId(): ?int;

    /**
     * Set attached customer id.
     *
     * @param int|null $customerId
     * @return $this
     */
    public function setCustomerId(?int $customerId): self;

    /**
     * Get serialized cart JSON.
     *
     * @return string
     */
    public function getCartJson(): string;

    /**
     * Set serialized cart JSON.
     *
     * @param string $cartJson
     * @return $this
     */
    public function setCartJson(string $cartJson): self;

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
