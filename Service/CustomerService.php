<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Helper\Config;

class CustomerService
{
    public const MAX_RESULTS = 20;

    private array $groupLabels = [];

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly AccountManagementInterface $accountManagement,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly DirectoryHelper $directoryHelper,
        private readonly Random $mathRandom,
        private readonly Config $config
    ) {
    }

    public function search(string $q): array
    {
        $query = trim($q);
        if ($query === '') {
            return [];
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        $filters = [];
        foreach (['firstname', 'lastname', 'email'] as $field) {
            $filters[] = $this->filterBuilder
                ->setField($field)
                ->setConditionType('like')
                ->setValue($like)
                ->create();
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilters($filters)
            ->setPageSize(self::MAX_RESULTS)
            ->setCurrentPage(1)
            ->create();

        $rows = [];
        foreach ($this->customerRepository->getList($searchCriteria)->getItems() as $customer) {
            $rows[] = $this->toRow($customer);
        }

        return $rows;
    }

    public function create(array $data): array
    {
        $firstname = trim((string)($data['firstname'] ?? ''));
        $lastname = trim((string)($data['lastname'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));

        if ($firstname === '' || $lastname === '') {
            throw new LocalizedException(__('First name and last name are required.'));
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('Please enter a valid email address.'));
        }

        $store = $this->storeManager->getStore();
        $storeId = (int)$store->getId();

        $customer = $this->customerFactory->create();
        $customer->setFirstname($firstname);
        $customer->setLastname($lastname);
        $customer->setEmail($email);
        $customer->setStoreId($storeId);
        $customer->setWebsiteId((int)$store->getWebsiteId());
        $customer->setGroupId($this->config->getDefaultCustomerGroupId($storeId));

        $customer = $this->accountManagement->createAccount($customer, $this->generateStrongPassword());

        if ($phone !== '') {
            $this->saveDefaultAddress($customer, $phone);
        }

        $row = $this->toRow($customer);
        if ($row['phone'] === null && $phone !== '') {
            $row['phone'] = $phone;
        }

        return $row;
    }

    private function toRow(CustomerInterface $customer): array
    {
        $groupId = (int)$customer->getGroupId();

        return [
            'id' => (int)$customer->getId(),
            'name' => trim($customer->getFirstname() . ' ' . $customer->getLastname()),
            'email' => (string)$customer->getEmail(),
            'group' => $this->getGroupLabel($groupId),
            'group_id' => $groupId,
            'phone' => $this->extractPhone($customer),
        ];
    }

    private function getGroupLabel(int $groupId): string
    {
        if (isset($this->groupLabels[$groupId])) {
            return $this->groupLabels[$groupId];
        }
        try {
            $label = (string)$this->groupRepository->getById($groupId)->getCode();
        } catch (LocalizedException $e) {
            $label = (string)$groupId;
        }
        $this->groupLabels[$groupId] = $label;

        return $label;
    }

    private function extractPhone(CustomerInterface $customer): ?string
    {
        $addresses = $customer->getAddresses() ?? [];
        if ($addresses === []) {
            return null;
        }

        $defaultBillingId = $customer->getDefaultBilling();
        $fallback = null;
        foreach ($addresses as $address) {
            $telephone = trim((string)$address->getTelephone());
            if ($telephone === '') {
                continue;
            }
            if ($defaultBillingId !== null && (string)$address->getId() === (string)$defaultBillingId) {
                return $telephone;
            }
            $fallback = $fallback ?? $telephone;
        }

        return $fallback;
    }

    private function saveDefaultAddress(CustomerInterface $customer, string $phone): void
    {
        try {
            $countryId = (string)$this->directoryHelper->getDefaultCountry($this->storeManager->getStore());
            if ($countryId === '') {
                $countryId = 'US';
            }
            $address = $this->addressFactory->create();
            $address->setCustomerId((int)$customer->getId());
            $address->setFirstname((string)$customer->getFirstname());
            $address->setLastname((string)$customer->getLastname());
            $address->setTelephone($phone);
            $address->setCountryId($countryId);
            $address->setStreet(['N/A']);
            $address->setCity('N/A');
            $address->setPostcode('00000');
            $address->setIsDefaultBilling(true);
            $address->setIsDefaultShipping(true);
            $this->addressRepository->save($address);
        } catch (\Exception $e) {
            unset($e);
        }
    }

    private function generateStrongPassword(): string
    {
        $password = $this->mathRandom->getRandomString(10, Random::CHARS_LOWERS)
            . $this->mathRandom->getRandomString(4, Random::CHARS_UPPERS)
            . $this->mathRandom->getRandomString(4, Random::CHARS_DIGITS)
            . $this->mathRandom->getRandomString(2, '!@#$%^&*');

        return str_shuffle($password);
    }
}
