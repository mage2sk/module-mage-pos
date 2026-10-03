<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Model\ResourceModel\PosUser as PosUserResource;

class PosUser extends AbstractModel implements PosUserInterface
{
    protected $_idFieldName = PosUserResource::ID_FIELD_NAME;

    protected $_eventPrefix = 'panth_pos_user';

    protected function _construct(): void
    {
        $this->_init(PosUserResource::class);
    }

    public function getUserId(): ?int
    {
        $value = $this->getData(self::USER_ID);

        return $value === null ? null : (int) $value;
    }

    public function setUserId(int $userId): PosUserInterface
    {
        return $this->setData(self::USER_ID, $userId);
    }

    public function getUsername(): string
    {
        return (string) $this->getData(self::USERNAME);
    }

    public function setUsername(string $username): PosUserInterface
    {
        return $this->setData(self::USERNAME, $username);
    }

    public function getName(): string
    {
        return (string) $this->getData(self::NAME);
    }

    public function setName(string $name): PosUserInterface
    {
        return $this->setData(self::NAME, $name);
    }

    public function getEmail(): string
    {
        return (string) $this->getData(self::EMAIL);
    }

    public function setEmail(string $email): PosUserInterface
    {
        return $this->setData(self::EMAIL, $email);
    }

    public function getPasswordHash(): string
    {
        return (string) $this->getData(self::PASSWORD_HASH);
    }

    public function setPasswordHash(string $passwordHash): PosUserInterface
    {
        return $this->setData(self::PASSWORD_HASH, $passwordHash);
    }

    public function getPinHash(): ?string
    {
        $value = $this->getData(self::PIN_HASH);

        return $value === null ? null : (string) $value;
    }

    public function setPinHash(?string $pinHash): PosUserInterface
    {
        return $this->setData(self::PIN_HASH, $pinHash);
    }

    public function getRoleId(): ?int
    {
        $value = $this->getData(self::ROLE_ID);

        return $value === null ? null : (int) $value;
    }

    public function setRoleId(?int $roleId): PosUserInterface
    {
        return $this->setData(self::ROLE_ID, $roleId);
    }

    public function getStatus(): int
    {
        return (int) $this->getData(self::STATUS);
    }

    public function setStatus(int $status): PosUserInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getLastLoginAt(): ?string
    {
        $value = $this->getData(self::LAST_LOGIN_AT);

        return $value === null ? null : (string) $value;
    }

    public function setLastLoginAt(?string $lastLoginAt): PosUserInterface
    {
        return $this->setData(self::LAST_LOGIN_AT, $lastLoginAt);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);

        return $value === null ? null : (string) $value;
    }

    public function setCreatedAt(string $createdAt): PosUserInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);

        return $value === null ? null : (string) $value;
    }

    public function setUpdatedAt(string $updatedAt): PosUserInterface
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }
}
