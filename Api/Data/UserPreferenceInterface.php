<?php
declare(strict_types=1);

namespace Panth\MagePos\Api\Data;

/**
 * POS user preference data interface.
 *
 * Represents a row in `panth_pos_user_preference` - per-user persisted
 * terminal layout (24-col grid panel placement) and theme settings JSON.
 */
interface UserPreferenceInterface
{
    public const PREFERENCE_ID = 'preference_id';
    public const USER_ID       = 'user_id';
    public const LAYOUT_JSON   = 'layout_json';
    public const THEME_JSON    = 'theme_json';
    public const CREATED_AT    = 'created_at';
    public const UPDATED_AT    = 'updated_at';

    /**
     * Get preference id.
     *
     * @return int|null
     */
    public function getPreferenceId(): ?int;

    /**
     * Set preference id.
     *
     * @param int $preferenceId
     * @return $this
     */
    public function setPreferenceId(int $preferenceId): self;

    /**
     * Get POS user id (unique).
     *
     * @return int
     */
    public function getUserId(): int;

    /**
     * Set POS user id (unique).
     *
     * @param int $userId
     * @return $this
     */
    public function setUserId(int $userId): self;

    /**
     * Get layout JSON.
     *
     * @return string|null
     */
    public function getLayoutJson(): ?string;

    /**
     * Set layout JSON.
     *
     * @param string|null $layoutJson
     * @return $this
     */
    public function setLayoutJson(?string $layoutJson): self;

    /**
     * Get theme JSON.
     *
     * @return string|null
     */
    public function getThemeJson(): ?string;

    /**
     * Set theme JSON.
     *
     * @param string|null $themeJson
     * @return $this
     */
    public function setThemeJson(?string $themeJson): self;

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
