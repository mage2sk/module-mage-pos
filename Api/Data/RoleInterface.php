<?php
declare(strict_types=1);

namespace Panth\MagePos\Api\Data;

/**
 * POS role data interface.
 *
 * Represents a row in `panth_pos_role`. The `permissions` column is a JSON
 * object: {max_discount_percent:int, can_price_override:bool, can_refund:bool,
 * can_open_close:bool, can_cash_inout:bool, can_custom_product:bool,
 * can_edit_layout:bool, can_view_reports:bool}.
 */
interface RoleInterface
{
    public const ROLE_ID     = 'role_id';
    public const NAME        = 'name';
    public const PERMISSIONS = 'permissions';
    public const CREATED_AT  = 'created_at';
    public const UPDATED_AT  = 'updated_at';

    /**
     * Get role id.
     *
     * @return int|null
     */
    public function getRoleId(): ?int;

    /**
     * Set role id.
     *
     * @param int $roleId
     * @return $this
     */
    public function setRoleId(int $roleId): self;

    /**
     * Get role name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Set role name.
     *
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self;

    /**
     * Get permissions JSON string.
     *
     * @return string
     */
    public function getPermissions(): string;

    /**
     * Set permissions JSON string.
     *
     * @param string $permissions
     * @return $this
     */
    public function setPermissions(string $permissions): self;

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
