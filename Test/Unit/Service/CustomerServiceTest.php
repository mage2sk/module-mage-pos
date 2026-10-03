<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Math\Random;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\CustomerService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CustomerServiceTest extends TestCase
{
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private CustomerInterfaceFactory&MockObject $customerFactory;
    private AccountManagementInterface&MockObject $accountManagement;
    private GroupRepositoryInterface&MockObject $groupRepository;
    private AddressInterfaceFactory&MockObject $addressFactory;
    private AddressRepositoryInterface&MockObject $addressRepository;
    private SearchCriteriaBuilder&MockObject $criteriaBuilder;
    private DirectoryHelper&MockObject $directoryHelper;
    private Random&MockObject $random;
    private array $filterFields = [];
    private array $filterValues = [];

    protected function setUp(): void
    {
        $this->filterFields = [];
        $this->filterValues = [];
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerFactory = $this->createMock(CustomerInterfaceFactory::class);
        $this->accountManagement = $this->createMock(AccountManagementInterface::class);
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $this->addressFactory = $this->createMock(AddressInterfaceFactory::class);
        $this->addressRepository = $this->createMock(AddressRepositoryInterface::class);
        $this->criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->directoryHelper = $this->createMock(DirectoryHelper::class);
        $this->random = $this->createMock(Random::class);

        $group = $this->createStub(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturnCallback(
            static function (int $id) use ($group) {
                if ($id === 1) {
                    return $group;
                }
                throw new NoSuchEntityException(__('no group'));
            }
        );
        $this->random->method('getRandomString')->willReturnCallback(
            static fn (int $len, ?string $chars = null) => str_repeat(((string) $chars)[0] ?? 'a', $len)
        );
    }

    private function makeService(): CustomerService
    {
        $filterBuilder = $this->createStub(FilterBuilder::class);
        $filterBuilder->method('setField')->willReturnCallback(function ($f) use ($filterBuilder) {
            $this->filterFields[] = $f;
            return $filterBuilder;
        });
        $filterBuilder->method('setConditionType')->willReturnSelf();
        $filterBuilder->method('setValue')->willReturnCallback(function ($v) use ($filterBuilder) {
            $this->filterValues[] = $v;
            return $filterBuilder;
        });
        $filterBuilder->method('create')->willReturnCallback(fn () => $this->createStub(Filter::class));
        $this->criteriaBuilder->method('addFilters')->willReturnSelf();
        $this->criteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->criteriaBuilder->method('setCurrentPage')->willReturnSelf();
        $this->criteriaBuilder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(2);
        $store->method('getWebsiteId')->willReturn(5);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $config = $this->createStub(Config::class);
        $config->method('getDefaultCustomerGroupId')->willReturn(1);

        return new CustomerService(
            $this->customerRepository,
            $this->customerFactory,
            $this->accountManagement,
            $this->groupRepository,
            $this->addressFactory,
            $this->addressRepository,
            $this->criteriaBuilder,
            $filterBuilder,
            $storeManager,
            $this->directoryHelper,
            $this->random,
            $config
        );
    }

    private function makeAddressStub(int $id, string $phone): AddressInterface
    {
        $address = $this->createStub(AddressInterface::class);
        $address->method('getId')->willReturn($id);
        $address->method('getTelephone')->willReturn($phone);

        return $address;
    }

    private function makeCustomer(int $id, int $groupId, ?array $addresses = null, ?string $defaultBilling = null): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getFirstname')->willReturn('Ann');
        $customer->method('getLastname')->willReturn('Lee');
        $customer->method('getEmail')->willReturn('ann@x.test');
        $customer->method('getGroupId')->willReturn($groupId);
        $customer->method('getAddresses')->willReturn($addresses);
        $customer->method('getDefaultBilling')->willReturn($defaultBilling);

        return $customer;
    }

    public function testBlankQueryReturnsNothingWithoutSearching(): void
    {
        $this->customerRepository->expects($this->never())->method('getList');

        $this->assertSame([], $this->makeService()->search('   '));
    }

    public function testSearchMatchesNameAndEmailWithEscapedLikeValue(): void
    {
        $results = $this->createStub(CustomerSearchResultsInterface::class);
        $results->method('getItems')->willReturn([
            $this->makeCustomer(4, 1, [$this->makeAddressStub(1, ' '), $this->makeAddressStub(2, '0111'), $this->makeAddressStub(3, '0222')], '3'),
            $this->makeCustomer(5, 9, null),
        ]);
        $this->customerRepository->expects($this->once())->method('getList')->willReturn($results);

        $rows = $this->makeService()->search(' 50%_off ');

        $this->assertSame(['firstname', 'lastname', 'email'], $this->filterFields);
        $this->assertSame(array_fill(0, 3, '%50\\%\\_off%'), $this->filterValues);
        $this->assertSame(
            ['id' => 4, 'name' => 'Ann Lee', 'email' => 'ann@x.test', 'group' => 'General', 'group_id' => 1, 'phone' => '0222'],
            $rows[0]
        );
        $this->assertSame('9', $rows[1]['group']);
        $this->assertNull($rows[1]['phone']);
    }

    public function testPhoneFallsBackToFirstNonEmptyTelephone(): void
    {
        $results = $this->createStub(CustomerSearchResultsInterface::class);
        $results->method('getItems')->willReturn([
            $this->makeCustomer(4, 1, [$this->makeAddressStub(1, ''), $this->makeAddressStub(2, '0111'), $this->makeAddressStub(3, '0222')], '99'),
        ]);
        $this->customerRepository->method('getList')->willReturn($results);

        $this->assertSame('0111', $this->makeService()->search('ann')[0]['phone']);
    }

    public static function invalidCreateProvider(): array
    {
        return [
            'no first name' => [['lastname' => 'L', 'email' => 'a@b.co'], 'First name and last name are required.'],
            'no last name' => [['firstname' => 'F', 'lastname' => '  ', 'email' => 'a@b.co'], 'First name and last name are required.'],
            'no email' => [['firstname' => 'F', 'lastname' => 'L'], 'Please enter a valid email address.'],
            'bad email' => [['firstname' => 'F', 'lastname' => 'L', 'email' => 'nope'], 'Please enter a valid email address.'],
        ];
    }

    #[DataProvider('invalidCreateProvider')]
    public function testCreateValidatesInput(array $data, string $message): void
    {
        $this->accountManagement->expects($this->never())->method('createAccount');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);
        $this->makeService()->create($data);
    }

    public function testCreateBuildsAccountInCurrentStoreWithStrongPasswordAndAddress(): void
    {
        $new = $this->createMock(CustomerInterface::class);
        $new->expects($this->once())->method('setFirstname')->with('Ann');
        $new->expects($this->once())->method('setLastname')->with('Lee');
        $new->expects($this->once())->method('setEmail')->with('ann@x.test');
        $new->expects($this->once())->method('setStoreId')->with(2);
        $new->expects($this->once())->method('setWebsiteId')->with(5);
        $new->expects($this->once())->method('setGroupId')->with(1);
        $this->customerFactory->method('create')->willReturn($new);
        $created = $this->makeCustomer(11, 1, []);
        $this->accountManagement->expects($this->once())->method('createAccount')
            ->with($new, $this->callback(static function (string $password) {
                return strlen($password) === 20
                    && preg_match('/[a-z]/', $password)
                    && preg_match('/[A-Z]/', $password)
                    && preg_match('/\d/', $password)
                    && preg_match('/[!@#$%^&*]/', $password);
            }))
            ->willReturn($created);
        $this->directoryHelper->method('getDefaultCountry')->willReturn('');
        $address = $this->createMock(AddressInterface::class);
        $address->expects($this->once())->method('setCustomerId')->with(11);
        $address->expects($this->once())->method('setTelephone')->with('07700');
        $address->expects($this->once())->method('setCountryId')->with('US');
        $address->expects($this->once())->method('setIsDefaultBilling')->with(true);
        $this->addressFactory->method('create')->willReturn($address);
        $this->addressRepository->expects($this->once())->method('save')->with($address);

        $row = $this->makeService()->create([
            'firstname' => ' Ann ',
            'lastname' => 'Lee',
            'email' => ' ann@x.test ',
            'phone' => ' 07700 ',
        ]);

        $this->assertSame(11, $row['id']);
        $this->assertSame('07700', $row['phone']);
        $this->assertSame('General', $row['group']);
    }

    public function testCreateWithoutPhoneSkipsAddressAndSurvivesAddressFailure(): void
    {
        $this->customerFactory->method('create')->willReturn($this->createStub(CustomerInterface::class));
        $this->accountManagement->method('createAccount')->willReturn($this->makeCustomer(11, 1, []));
        $this->addressFactory->expects($this->never())->method('create');

        $row = $this->makeService()->create(['firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.co']);

        $this->assertNull($row['phone']);
    }

    public function testAddressSaveFailureDoesNotBreakCustomerCreation(): void
    {
        $this->customerFactory->method('create')->willReturn($this->createStub(CustomerInterface::class));
        $this->accountManagement->method('createAccount')->willReturn($this->makeCustomer(11, 1, []));
        $this->directoryHelper->method('getDefaultCountry')->willReturn('GB');
        $this->addressFactory->method('create')->willReturn($this->createStub(AddressInterface::class));
        $this->addressRepository->method('save')->willThrowException(new \RuntimeException('invalid address'));

        $row = $this->makeService()->create(['firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.co', 'phone' => '123']);

        $this->assertSame(11, $row['id']);
        $this->assertSame('123', $row['phone']);
    }
}
