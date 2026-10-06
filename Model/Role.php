<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\MagePos\Api\Data\RoleInterface;
use Panth\MagePos\Model\ResourceModel\Role as RoleResource;

class Role extends AbstractModel implements RoleInterface
{
    protected $_idFieldName = RoleResource::ID_FIELD_NAME;

    protected $_eventPrefix = 'panth_pos_role';

    protected function _construct(): void
    {
        $this->_init(RoleResource::class);
    }

    public function getRoleId(): ?int
    {
        $value = $this->getData(self::ROLE_ID);

        return $value === null ? null : (int) $value;
    }

    public function setRoleId(int $roleId): RoleInterface
    {
        return $this->setData(self::ROLE_ID, $roleId);
    }

    public function getName(): string
    {
        return (string) $this->getData(self::NAME);
    }

    public function setName(string $name): RoleInterface
    {
        return $this->setData(self::NAME, $name);
    }

    public function getPermissions(): string
    {
        return (string) $this->getData(self::PERMISSIONS);
    }

    public function setPermissions(string $permissions): RoleInterface
    {
        return $this->setData(self::PERMISSIONS, $permissions);
    }

    public function getPermissionsArray(): array
    {
        $json = trim($this->getPermissions());
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function setPermissionsArray(array $permissions): self
    {
        $encoded = json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->setPermissions($encoded !== false ? $encoded : '{}');

        return $this;
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);

        return $value === null ? null : (string) $value;
    }

    public function setCreatedAt(string $createdAt): RoleInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);

        return $value === null ? null : (string) $value;
    }

    public function setUpdatedAt(string $updatedAt): RoleInterface
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }
}
