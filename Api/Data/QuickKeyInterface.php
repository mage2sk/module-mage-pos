<?php
declare(strict_types=1);

namespace Panth\MagePos\Api\Data;

/**
 * Quick key data interface.
 *
 * Represents a row in `panth_pos_quick_key` - a favorite product tile shown
 * on the POS terminal. register_id NULL means the tile shows on all
 * registers.
 */
interface QuickKeyInterface
{
    public const QUICK_KEY_ID = 'quick_key_id';
    public const REGISTER_ID  = 'register_id';
    public const PRODUCT_ID   = 'product_id';
    public const LABEL        = 'label';
    public const COLOR        = 'color';
    public const POSITION     = 'position';
    public const PAGE         = 'page';
    public const CREATED_AT   = 'created_at';
    public const UPDATED_AT   = 'updated_at';

    /**
     * Get quick key id.
     *
     * @return int|null
     */
    public function getQuickKeyId(): ?int;

    /**
     * Set quick key id.
     *
     * @param int $quickKeyId
     * @return $this
     */
    public function setQuickKeyId(int $quickKeyId): self;

    /**
     * Get register id (null = all registers).
     *
     * @return int|null
     */
    public function getRegisterId(): ?int;

    /**
     * Set register id (null = all registers).
     *
     * @param int|null $registerId
     * @return $this
     */
    public function setRegisterId(?int $registerId): self;

    /**
     * Get product id.
     *
     * @return int
     */
    public function getProductId(): int;

    /**
     * Set product id.
     *
     * @param int $productId
     * @return $this
     */
    public function setProductId(int $productId): self;

    /**
     * Get tile label (null = product name).
     *
     * @return string|null
     */
    public function getLabel(): ?string;

    /**
     * Set tile label (null = product name).
     *
     * @param string|null $label
     * @return $this
     */
    public function setLabel(?string $label): self;

    /**
     * Get tile color.
     *
     * @return string|null
     */
    public function getColor(): ?string;

    /**
     * Set tile color.
     *
     * @param string|null $color
     * @return $this
     */
    public function setColor(?string $color): self;

    /**
     * Get tile position within the page.
     *
     * @return int
     */
    public function getPosition(): int;

    /**
     * Set tile position within the page.
     *
     * @param int $position
     * @return $this
     */
    public function setPosition(int $position): self;

    /**
     * Get quick key page number.
     *
     * @return int
     */
    public function getPage(): int;

    /**
     * Set quick key page number.
     *
     * @param int $page
     * @return $this
     */
    public function setPage(int $page): self;

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
