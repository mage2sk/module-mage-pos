<?php
declare(strict_types=1);

namespace Panth\MagePos\Api\Data;

/**
 * POS payment method data interface.
 *
 * Represents a row in `panth_pos_payment_method` - an admin-defined tender
 * type (cash | offline | online) usable at the POS terminal.
 */
interface PaymentMethodInterface
{
    public const METHOD_ID            = 'method_id';
    public const CODE                 = 'code';
    public const TITLE                = 'title';
    public const TYPE                 = 'type';
    public const IS_ACTIVE            = 'is_active';
    public const SORT_ORDER           = 'sort_order';
    public const ICON                 = 'icon';
    public const REQUIRES_REFERENCE   = 'requires_reference';
    public const INSTRUCTIONS         = 'instructions';
    public const OPEN_DRAWER          = 'open_drawer';
    public const PAYMENT_URL_TEMPLATE = 'payment_url_template';
    public const CREATED_AT           = 'created_at';
    public const UPDATED_AT           = 'updated_at';

    public const TYPE_CASH    = 'cash';
    public const TYPE_OFFLINE = 'offline';
    public const TYPE_ONLINE  = 'online';

    /**
     * Get method id.
     *
     * @return int|null
     */
    public function getMethodId(): ?int;

    /**
     * Set method id.
     *
     * @param int $methodId
     * @return $this
     */
    public function setMethodId(int $methodId): self;

    /**
     * Get unique method code.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Set unique method code.
     *
     * @param string $code
     * @return $this
     */
    public function setCode(string $code): self;

    /**
     * Get method title.
     *
     * @return string
     */
    public function getTitle(): string;

    /**
     * Set method title.
     *
     * @param string $title
     * @return $this
     */
    public function setTitle(string $title): self;

    /**
     * Get method type (cash|offline|online).
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Set method type (cash|offline|online).
     *
     * @param string $type
     * @return $this
     */
    public function setType(string $type): self;

    /**
     * Get active flag (1 = active, 0 = inactive).
     *
     * @return int
     */
    public function getIsActive(): int;

    /**
     * Set active flag (1 = active, 0 = inactive).
     *
     * @param int $isActive
     * @return $this
     */
    public function setIsActive(int $isActive): self;

    /**
     * Get sort order.
     *
     * @return int
     */
    public function getSortOrder(): int;

    /**
     * Set sort order.
     *
     * @param int $sortOrder
     * @return $this
     */
    public function setSortOrder(int $sortOrder): self;

    /**
     * Get icon (emoji or css class).
     *
     * @return string|null
     */
    public function getIcon(): ?string;

    /**
     * Set icon (emoji or css class).
     *
     * @param string|null $icon
     * @return $this
     */
    public function setIcon(?string $icon): self;

    /**
     * Get requires-reference flag (1 = cashier must enter a reference).
     *
     * @return int
     */
    public function getRequiresReference(): int;

    /**
     * Set requires-reference flag (1 = cashier must enter a reference).
     *
     * @param int $requiresReference
     * @return $this
     */
    public function setRequiresReference(int $requiresReference): self;

    /**
     * Get cashier instructions text.
     *
     * @return string|null
     */
    public function getInstructions(): ?string;

    /**
     * Set cashier instructions text.
     *
     * @param string|null $instructions
     * @return $this
     */
    public function setInstructions(?string $instructions): self;

    /**
     * Get open-drawer flag (1 = open cash drawer on tender).
     *
     * @return int
     */
    public function getOpenDrawer(): int;

    /**
     * Set open-drawer flag (1 = open cash drawer on tender).
     *
     * @param int $openDrawer
     * @return $this
     */
    public function setOpenDrawer(int $openDrawer): self;

    /**
     * Get online payment URL template (%increment_id%, %amount% tokens).
     *
     * @return string|null
     */
    public function getPaymentUrlTemplate(): ?string;

    /**
     * Set online payment URL template (%increment_id%, %amount% tokens).
     *
     * @param string|null $paymentUrlTemplate
     * @return $this
     */
    public function setPaymentUrlTemplate(?string $paymentUrlTemplate): self;

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
