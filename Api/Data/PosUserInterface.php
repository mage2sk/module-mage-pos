<?php
declare(strict_types=1);

namespace Panth\MagePos\Api\Data;

/**
 * POS user (cashier) data interface.
 *
 * Represents a row in `panth_pos_user`. Credentials are stored as
 * password_hash() hashes; PIN is an optional fast re-unlock secret.
 */
interface PosUserInterface
{
    public const USER_ID       = 'user_id';
    public const USERNAME      = 'username';
    public const NAME          = 'name';
    public const EMAIL         = 'email';
    public const PASSWORD_HASH = 'password_hash';
    public const PIN_HASH      = 'pin_hash';
    public const ROLE_ID       = 'role_id';
    public const STATUS        = 'status';
    public const LAST_LOGIN_AT = 'last_login_at';
    public const CREATED_AT    = 'created_at';
    public const UPDATED_AT    = 'updated_at';

    /**
     * Get user id.
     *
     * @return int|null
     */
    public function getUserId(): ?int;

    /**
     * Set user id.
     *
     * @param int $userId
     * @return $this
     */
    public function setUserId(int $userId): self;

    /**
     * Get unique username.
     *
     * @return string
     */
    public function getUsername(): string;

    /**
     * Set unique username.
     *
     * @param string $username
     * @return $this
     */
    public function setUsername(string $username): self;

    /**
     * Get display name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Set display name.
     *
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self;

    /**
     * Get email address.
     *
     * @return string
     */
    public function getEmail(): string;

    /**
     * Set email address.
     *
     * @param string $email
     * @return $this
     */
    public function setEmail(string $email): self;

    /**
     * Get password hash.
     *
     * @return string
     */
    public function getPasswordHash(): string;

    /**
     * Set password hash.
     *
     * @param string $passwordHash
     * @return $this
     */
    public function setPasswordHash(string $passwordHash): self;

    /**
     * Get PIN hash.
     *
     * @return string|null
     */
    public function getPinHash(): ?string;

    /**
     * Set PIN hash.
     *
     * @param string|null $pinHash
     * @return $this
     */
    public function setPinHash(?string $pinHash): self;

    /**
     * Get assigned role id.
     *
     * @return int|null
     */
    public function getRoleId(): ?int;

    /**
     * Set assigned role id.
     *
     * @param int|null $roleId
     * @return $this
     */
    public function setRoleId(?int $roleId): self;

    /**
     * Get status (1 = enabled, 0 = disabled).
     *
     * @return int
     */
    public function getStatus(): int;

    /**
     * Set status (1 = enabled, 0 = disabled).
     *
     * @param int $status
     * @return $this
     */
    public function setStatus(int $status): self;

    /**
     * Get last login timestamp.
     *
     * @return string|null
     */
    public function getLastLoginAt(): ?string;

    /**
     * Set last login timestamp.
     *
     * @param string|null $lastLoginAt
     * @return $this
     */
    public function setLastLoginAt(?string $lastLoginAt): self;

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
