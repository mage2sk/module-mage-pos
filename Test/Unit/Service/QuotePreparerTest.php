<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\ResourceModel\Region\Collection as RegionCollection;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Store\Model\Information as StoreInformation;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\QuotePreparer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class QuotePreparerTest extends TestCase
{
    private array $storeInfo = [];
    private bool $regionRequired = false;
    private int $firstRegionId = 0;

    protected function setUp(): void
    {
        $this->storeInfo = [];
        $this->regionRequired = false;
        $this->firstRegionId = 0;
    }

    private function makePreparer(string $defaultCountry = 'GB'): QuotePreparer
    {
        $config = $this->createStub(Config::class);
        $config->method('getGuestEmail')->willReturn('guest@pos.test');
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn ($path) => $this->storeInfo[$path] ?? null);
        $directory = $this->createStub(DirectoryHelper::class);
        $directory->method('getDefaultCountry')->willReturn($defaultCountry);
        $directory->method('isRegionRequired')->willReturnCallback(fn () => $this->regionRequired);
        $collection = $this->createStub(RegionCollection::class);
        $collection->method('getFirstItem')->willReturnCallback(
            fn () => new DataObject(['id' => $this->firstRegionId])
        );
        $regionFactory = $this->createStub(RegionCollectionFactory::class);
        $regionFactory->method('create')->willReturn($collection);

        return new QuotePreparer($config, $scopeConfig, $directory, $regionFactory);
    }

    private function makeAddress(array $rates = [], array $data = []): Address&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRegionId', 'getStreetLine', 'collectShippingRates', 'getAllShippingRates', 'setStreet'])
            ->getMock();
        $address->setData($data);
        $address->method('getRegionId')->willReturnCallback(fn () => $address->getData('region_id'));
        $address->method('getStreetLine')->willReturnCallback(
            fn ($n) => explode("
", (string) $address->getData('street'))[$n - 1] ?? ''
        );
        $address->method('setStreet')->willReturnCallback(function ($street) use ($address) {
            $address->setData('street', $street);
            return $address;
        });
        $address->method('collectShippingRates')->willReturnSelf();
        $address->method('getAllShippingRates')->willReturn($rates);

        return $address;
    }

    private function makeRate(string $code, float $price): Rate
    {
        $rate = $this->getMockBuilder(Rate::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $rate->setData(['code' => $code, 'price' => $price]);

        return $rate;
    }

    private function makeQuote(Address $billing, ?Address $shipping, bool $virtual, array $data = [], ?CustomerInterface $customer = null): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'getCustomer', 'getBillingAddress', 'getShippingAddress', 'isVirtual', 'setCustomerIsGuest', 'setCheckoutMethod'])
            ->getMock();
        $quote->setData($data);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getCustomer')->willReturn($customer);
        $quote->method('getBillingAddress')->willReturn($billing);
        $quote->method('getShippingAddress')->willReturn($shipping);
        $quote->method('isVirtual')->willReturn($virtual);
        $quote->method('setCustomerIsGuest')->willReturnCallback(function ($v) use ($quote) {
            $quote->setData('customer_is_guest', $v);
            return $quote;
        });
        $quote->method('setCheckoutMethod')->willReturnCallback(function ($v) use ($quote) {
            $quote->setData('checkout_method', $v);
            return $quote;
        });

        return $quote;
    }

    public function testGuestQuoteGetsGuestIdentityAndFallbackAddressesWithFreeShipping(): void
    {
        $billing = $this->makeAddress();
        $flat = $this->makeRate('flatrate_flatrate', 5.0);
        $free = $this->makeRate('freeshipping_freeshipping', 0.0);
        $shipping = $this->makeAddress([$flat, $free]);
        $quote = $this->makeQuote($billing, $shipping, false);

        $this->makePreparer()->prepare($quote);

        $this->assertTrue($quote->getData('customer_is_guest'));
        $this->assertSame('guest', $quote->getData('checkout_method'));
        $this->assertSame('guest@pos.test', $quote->getCustomerEmail());
        $this->assertSame('POS', $quote->getCustomerFirstname());
        $this->assertSame('Customer', $quote->getCustomerLastname());
        foreach ([$billing, $shipping] as $address) {
            $this->assertSame('POS', $address->getData('firstname'));
            $this->assertSame('POS', $address->getData('street'));
            $this->assertSame('00000', $address->getData('postcode'));
            $this->assertSame('0000000000', $address->getData('telephone'));
            $this->assertSame('GB', $address->getData('country_id'));
            $this->assertSame('guest@pos.test', $address->getData('email'));
        }
        $this->assertSame('freeshipping_freeshipping', $shipping->getData('shipping_method'));
        $this->assertSame(0.0, $free->getPrice());
        $this->assertSame(5.0, $flat->getPrice());
    }

    public function testStoreInformationIsUsedForAddressDefaults(): void
    {
        $this->storeInfo = [
            StoreInformation::XML_PATH_STORE_INFO_COUNTRY_CODE => 'DE',
            StoreInformation::XML_PATH_STORE_INFO_STREET_LINE1 => 'Main St 1',
            StoreInformation::XML_PATH_STORE_INFO_CITY => 'Berlin',
            StoreInformation::XML_PATH_STORE_INFO_POSTCODE => '10115',
            StoreInformation::XML_PATH_STORE_INFO_PHONE => '+49 30 1',
            StoreInformation::XML_PATH_STORE_INFO_REGION_CODE => '82',
        ];
        $this->regionRequired = true;
        $billing = $this->makeAddress();
        $quote = $this->makeQuote($billing, null, true);

        $this->makePreparer()->prepare($quote);

        $this->assertSame('Main St 1', $billing->getData('street'));
        $this->assertSame('Berlin', $billing->getData('city'));
        $this->assertSame('10115', $billing->getData('postcode'));
        $this->assertSame('+49 30 1', $billing->getData('telephone'));
        $this->assertSame('DE', $billing->getData('country_id'));
        $this->assertSame(82, $billing->getData('region_id'));
    }

    public function testRequiredRegionFallsBackToFirstRegionOfCountry(): void
    {
        $this->regionRequired = true;
        $this->firstRegionId = 12;
        $billing = $this->makeAddress();
        $this->makePreparer()->prepare($this->makeQuote($billing, null, true));

        $this->assertSame(12, $billing->getData('region_id'));
    }

    public function testCountryFallsBackToUsWhenNothingConfigured(): void
    {
        $billing = $this->makeAddress();
        $this->makePreparer('')->prepare($this->makeQuote($billing, null, true));

        $this->assertSame('US', $billing->getData('country_id'));
    }

    public function testExistingAddressDataIsPreserved(): void
    {
        $billing = $this->makeAddress([], [
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'street' => ['1 Real Rd'],
            'city' => 'Leeds',
            'telephone' => '0113',
            'country_id' => 'GB',
            'postcode' => 'LS1',
            'region_id' => 3,
        ]);
        $quote = $this->makeQuote($billing, null, true, ['customer_email' => 'jane@x.test', 'customer_firstname' => 'Jane']);

        $this->makePreparer()->prepare($quote);

        $this->assertSame('Jane', $billing->getData('firstname'));
        $this->assertSame('1 Real Rd', $billing->getData('street'));
        $this->assertSame('Leeds', $billing->getData('city'));
        $this->assertSame(3, $billing->getData('region_id'));
        $this->assertSame('jane@x.test', $billing->getData('email'));
        $this->assertNull($quote->getCustomerLastname());
    }

    public function testRegisteredCustomerGroupIsTakenFromCustomerAndEmailFilledWhenMissing(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(3);
        $billing = $this->makeAddress();
        $quote = $this->makeQuote($billing, null, true, ['customer_id' => 5, 'customer_group_id' => 1], $customer);

        $this->makePreparer()->prepare($quote);

        $this->assertSame(3, $quote->getData('customer_group_id'));
        $this->assertSame('guest@pos.test', $quote->getCustomerEmail());
        $this->assertNull($quote->getData('customer_is_guest'));
    }

    public function testCheapestRateIsPickedWhenNoFreeShipping(): void
    {
        $a = $this->makeRate('flatrate_flatrate', 7.5);
        $b = $this->makeRate('tablerate_bestway', 3.25);
        $shipping = $this->makeAddress([$a, $b]);

        $this->makePreparer()->prepare($this->makeQuote($this->makeAddress(), $shipping, false));

        $this->assertSame('tablerate_bestway', $shipping->getData('shipping_method'));
        $this->assertSame(0.0, $b->getPrice());
        $this->assertSame(7.5, $a->getPrice());
        $this->assertTrue((bool) $shipping->getData('collect_shipping_rates'));
    }

    public function testMissingRatesThrowWhenShippingMethodRequired(): void
    {
        $quote = $this->makeQuote($this->makeAddress(), $this->makeAddress([]), false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No shipping methods are available for the POS order.');
        $this->makePreparer()->prepare($quote);
    }

    public function testMissingRatesAreToleratedWhenShippingMethodOptional(): void
    {
        $shipping = $this->makeAddress([]);
        $this->makePreparer()->prepare($this->makeQuote($this->makeAddress(), $shipping, false), false);

        $this->assertSame('', $shipping->getData('shipping_method'));
    }
}
