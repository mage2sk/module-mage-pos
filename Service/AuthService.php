<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RoleRepositoryInterface;

class AuthService
{
    public const SESSION_KEY_USER_ID = 'panth_pos_user_id';

    public const SESSION_KEY_SESSION_ID = 'panth_pos_session_id';

    public const SESSION_KEY_LOCKED = 'panth_pos_locked';

    public const PERMISSION_KEYS = [
        'can_price_override',
        'can_refund',
        'can_open_close',
        'can_cash_inout',
        'can_custom_product',
        'can_edit_layout',
        'can_view_reports',
    ];

    private ?PosUserInterface $currentUser = null;

    private bool $currentUserLoaded = false;

    private ?array $permissionsCache = null;

    public function __construct(
        private readonly SessionManagerInterface $sessionManager,
        private readonly PosUserRepositoryInterface $posUserRepository,
        private readonly RoleRepositoryInterface $roleRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SerializerInterface $serializer
    ) {
    }

    public function login(string $u, string $p): array
    {
        $username = trim($u);
        if ($username === '' || $p === '') {
            throw new LocalizedException(__('Username and password are required.'));
        }

        $user = $this->findByUsername($username);
        if ($user === null || !password_verify($p, $user->getPasswordHash())) {
            throw new LocalizedException(__('Invalid username or password.'));
        }
        if ((int)$user->getStatus() !== 1) {
            throw new LocalizedException(__('This account is disabled.'));
        }

        $this->sessionManager->regenerateId();
        $this->sessionManager->setData(self::SESSION_KEY_USER_ID, (int)$user->getUserId());

        $this->sessionManager->unsetData(self::SESSION_KEY_LOCKED);

        $user->setLastLoginAt(gmdate('Y-m-d H:i:s'));
        try {
            $user = $this->posUserRepository->save($user);
        } catch (\Exception $e) {
            unset($e);
        }

        $this->currentUser = $user;
        $this->currentUserLoaded = true;
        $this->permissionsCache = null;

        return $this->buildUserPayload($user);
    }

    public function pinUnlock(string $username, string $pin): array
    {
        $current = $this->getCurrentUser();
        if ($current === null) {
            throw new LocalizedException(__('Your session has expired. Please sign in with username and password.'));
        }
        if (strcasecmp(trim($username), $current->getUsername()) !== 0) {
            throw new LocalizedException(__('PIN unlock is only available for the signed-in cashier.'));
        }

        $pinHash = $current->getPinHash();
        if ($pin === '' || $pinHash === null || $pinHash === '' || !password_verify($pin, $pinHash)) {
            throw new LocalizedException(__('Invalid PIN.'));
        }

        $this->sessionManager->unsetData(self::SESSION_KEY_LOCKED);

        return $this->buildUserPayload($current);
    }

    public function lock(): void
    {
        $this->sessionManager->setData(self::SESSION_KEY_LOCKED, true);
    }

    public function isLocked(): bool
    {
        return $this->getCurrentUser() !== null
            && (bool)$this->sessionManager->getData(self::SESSION_KEY_LOCKED);
    }

    public function logout(): void
    {
        $this->sessionManager->unsetData(self::SESSION_KEY_USER_ID);
        $this->sessionManager->unsetData(self::SESSION_KEY_LOCKED);
        $this->sessionManager->regenerateId();
        $this->currentUser = null;
        $this->currentUserLoaded = true;
        $this->permissionsCache = null;
    }

    public function getCurrentUser(): ?PosUserInterface
    {
        if ($this->currentUserLoaded) {
            return $this->currentUser;
        }
        $this->currentUserLoaded = true;
        $this->currentUser = null;

        $userId = (int)$this->sessionManager->getData(self::SESSION_KEY_USER_ID);
        if ($userId <= 0) {
            return null;
        }

        try {
            $user = $this->posUserRepository->getById($userId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
        if ((int)$user->getStatus() !== 1) {
            return null;
        }

        $this->currentUser = $user;

        return $this->currentUser;
    }

    public function requireUser(): PosUserInterface
    {
        $user = $this->getCurrentUser();
        if ($user === null || $this->isLocked()) {
            throw new LocalizedException(__('unauthorized'));
        }

        return $user;
    }

    public function hasPermission(string $key): bool
    {
        $permissions = $this->getPermissions();

        return (bool)($permissions[$key] ?? false);
    }

    public function getMaxDiscountPercent(): float
    {
        $permissions = $this->getPermissions();
        $max = $permissions['max_discount_percent'] ?? 0;
        if (!is_numeric($max)) {
            return 0.0;
        }

        return max(0.0, min(100.0, (float)$max));
    }

    public function requirePermission(string $key): void
    {
        $this->requireUser();
        if (!$this->hasPermission($key)) {
            throw new LocalizedException(__('You do not have the "%1" permission.', $key));
        }
    }

    public function buildUserPayload(PosUserInterface $user): array
    {
        $permissions = $this->getPermissionsForUser($user);
        $flags = [];
        foreach (self::PERMISSION_KEYS as $key) {
            $flags[$key] = (bool)($permissions[$key] ?? false);
        }
        $max = $permissions['max_discount_percent'] ?? 0;

        return [
            'id' => (int)$user->getUserId(),
            'name' => $user->getName(),
            'username' => $user->getUsername(),
            'permissions' => $flags,
            'max_discount_percent' => is_numeric($max) ? max(0.0, min(100.0, (float)$max)) : 0.0,
        ];
    }

    private function findByUsername(string $username): ?PosUserInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(PosUserInterface::USERNAME, $username)
            ->setPageSize(1)
            ->create();
        $items = $this->posUserRepository->getList($searchCriteria)->getItems();
        foreach ($items as $item) {
            if ($item instanceof PosUserInterface) {
                return $item;
            }
        }

        return null;
    }

    private function getPermissions(): array
    {
        if ($this->permissionsCache !== null) {
            return $this->permissionsCache;
        }
        $user = $this->getCurrentUser();
        $this->permissionsCache = $user === null ? [] : $this->getPermissionsForUser($user);

        return $this->permissionsCache;
    }

    private function getPermissionsForUser(PosUserInterface $user): array
    {
        $roleId = $user->getRoleId();
        if ($roleId === null || $roleId <= 0) {
            return [];
        }
        try {
            $role = $this->roleRepository->getById((int)$roleId);
        } catch (NoSuchEntityException $e) {
            return [];
        }

        $json = $role->getPermissions();
        if ($json === '') {
            return [];
        }
        try {
            $decoded = $this->serializer->unserialize($json);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
