<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\MagePos\Api\Data\UserPreferenceInterface;

class UserPreference extends AbstractModel implements UserPreferenceInterface
{
    public const CACHE_TAG = 'panth_pos_user_preference';

    protected $_cacheTag = self::CACHE_TAG;

    protected $_eventPrefix = 'panth_pos_user_preference';

    protected $_eventObject = 'user_preference';

    protected $_idFieldName = 'preference_id';

    protected function _construct(): void
    {
        $this->_init(\Panth\MagePos\Model\ResourceModel\UserPreference::class);
    }

    public function getIdentities(): array
    {
        return [self::CACHE_TAG . '_' . (int) $this->getId()];
    }

    public function getPreferenceId(): ?int
    {
        $value = $this->getData(self::PREFERENCE_ID);
        return $value === null ? null : (int) $value;
    }

    public function setPreferenceId(int $preferenceId): self
    {
        $this->setData(self::PREFERENCE_ID, $preferenceId);
        return $this;
    }

    public function getUserId(): int
    {
        return (int) $this->getData(self::USER_ID);
    }

    public function setUserId(int $userId): self
    {
        $this->setData(self::USER_ID, $userId);
        return $this;
    }

    public function getLayoutJson(): ?string
    {
        $value = $this->getData(self::LAYOUT_JSON);
        return $value === null ? null : (string) $value;
    }

    public function setLayoutJson(?string $layoutJson): self
    {
        $this->setData(self::LAYOUT_JSON, $layoutJson);
        return $this;
    }

    public function getThemeJson(): ?string
    {
        $value = $this->getData(self::THEME_JSON);
        return $value === null ? null : (string) $value;
    }

    public function setThemeJson(?string $themeJson): self
    {
        $this->setData(self::THEME_JSON, $themeJson);
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setCreatedAt(?string $createdAt): self
    {
        $this->setData(self::CREATED_AT, $createdAt);
        return $this;
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setUpdatedAt(?string $updatedAt): self
    {
        $this->setData(self::UPDATED_AT, $updatedAt);
        return $this;
    }
}
