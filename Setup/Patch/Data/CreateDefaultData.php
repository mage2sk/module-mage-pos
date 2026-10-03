<?php
declare(strict_types=1);

namespace Panth\MagePos\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Model\PaymentMethodFactory;
use Panth\MagePos\Model\PosUserFactory;
use Panth\MagePos\Model\RegisterFactory;
use Panth\MagePos\Model\ResourceModel\PaymentMethod as PaymentMethodResource;
use Panth\MagePos\Model\ResourceModel\PosUser as PosUserResource;
use Panth\MagePos\Model\ResourceModel\Register as RegisterResource;
use Panth\MagePos\Model\ResourceModel\Role as RoleResource;
use Panth\MagePos\Model\RoleFactory;
use Panth\MagePos\Service\CheckoutService;

class CreateDefaultData implements DataPatchInterface
{
    public const ROLE_ADMINISTRATOR = 'Administrator';
    public const ROLE_CASHIER = 'Cashier';
    public const DEFAULT_USERNAME = 'admin';
    public const DEFAULT_REGISTER_CODE = 'main';

    public function __construct(
        private readonly RoleFactory $roleFactory,
        private readonly RoleResource $roleResource,
        private readonly PosUserFactory $posUserFactory,
        private readonly PosUserResource $posUserResource,
        private readonly RegisterFactory $registerFactory,
        private readonly RegisterResource $registerResource,
        private readonly PaymentMethodFactory $paymentMethodFactory,
        private readonly PaymentMethodResource $paymentMethodResource,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $adminRoleId = $this->ensureRole(self::ROLE_ADMINISTRATOR, [
            'max_discount_percent' => 100,
            'can_price_override' => true,
            'can_refund' => true,
            'can_open_close' => true,
            'can_cash_inout' => true,
            'can_custom_product' => true,
            'can_edit_layout' => true,
            'can_view_reports' => true,
        ]);
        $this->ensureRole(self::ROLE_CASHIER, [
            'max_discount_percent' => 10,
            'can_price_override' => false,
            'can_refund' => false,
            'can_open_close' => true,
            'can_cash_inout' => true,
            'can_custom_product' => true,
            'can_edit_layout' => false,
            'can_view_reports' => false,
        ]);

        $this->ensureAdminUser($adminRoleId);
        $this->ensureMainRegister();

        $this->ensurePaymentMethod('cash', 'Cash', 'cash', [
            'open_drawer' => 1,
            'sort_order' => 10,
        ]);
        $this->ensurePaymentMethod('card', 'Card', 'offline', [
            'requires_reference' => 1,
            'sort_order' => 20,
        ]);

        $this->ensurePaymentMethod('payment_link', 'Payment Link', 'online', [
            'sort_order' => 30,
            'payment_url_template' => CheckoutService::DEFAULT_PAYMENT_URL_TEMPLATE,
        ]);

        return $this;
    }

    private function ensureRole(string $name, array $permissions): int
    {
        $role = $this->roleFactory->create();
        $this->roleResource->load($role, $name, 'name');
        if ($role->getId()) {
            return (int)$role->getId();
        }

        $role->setName($name);
        $role->setPermissions((string)json_encode($permissions, JSON_UNESCAPED_SLASHES));
        $this->roleResource->save($role);

        return (int)$role->getId();
    }

    private function ensureAdminUser(int $adminRoleId): void
    {
        $user = $this->posUserFactory->create();
        $this->posUserResource->load($user, self::DEFAULT_USERNAME, 'username');
        if ($user->getId()) {
            return;
        }

        $user->setUsername(self::DEFAULT_USERNAME);
        $user->setName('Administrator');
        $user->setEmail('pos-admin@example.com');
        $user->setPasswordHash(password_hash($this->generateUnusableSecret(), PASSWORD_DEFAULT));
        $user->setPinHash(password_hash($this->generateUnusableSecret(), PASSWORD_DEFAULT));
        $user->setRoleId($adminRoleId);
        $user->setStatus(0);
        $this->posUserResource->save($user);
    }

    private function generateUnusableSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function ensureMainRegister(): void
    {
        $register = $this->registerFactory->create();
        $this->registerResource->load($register, self::DEFAULT_REGISTER_CODE, 'code');
        if ($register->getId()) {
            return;
        }

        $defaultStore = $this->storeManager->getDefaultStoreView();
        $storeId = $defaultStore ? (int)$defaultStore->getId() : 1;

        $register->setName('Main Register');
        $register->setCode(self::DEFAULT_REGISTER_CODE);
        $register->setStoreId($storeId);
        $register->setStatus(1);
        $this->registerResource->save($register);
    }

    private function ensurePaymentMethod(string $code, string $title, string $type, array $extra): void
    {
        $method = $this->paymentMethodFactory->create();
        $this->paymentMethodResource->load($method, $code, 'code');
        if ($method->getId()) {
            return;
        }

        $method->setCode($code);
        $method->setTitle($title);
        $method->setType($type);
        $method->setIsActive(1);
        $method->setSortOrder((int)($extra['sort_order'] ?? 0));
        $method->setIcon(isset($extra['icon']) ? (string)$extra['icon'] : null);
        $method->setRequiresReference((int)($extra['requires_reference'] ?? 0));
        $method->setOpenDrawer((int)($extra['open_drawer'] ?? 0));
        $method->setPaymentUrlTemplate(
            isset($extra['payment_url_template']) ? (string)$extra['payment_url_template'] : null
        );
        $this->paymentMethodResource->save($method);
    }
}
