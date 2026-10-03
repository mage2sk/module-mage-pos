<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Store\Model\Information as StoreInformation;
use Magento\Store\Model\ScopeInterface;
use Panth\MagePos\Helper\Config;

class QuotePreparer
{
    public function __construct(
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DirectoryHelper $directoryHelper,
        private readonly RegionCollectionFactory $regionCollectionFactory
    ) {
    }

    public function prepare(Quote $quote, bool $requireShippingMethod = true): void
    {
        $storeId = (int)$quote->getStoreId();
        $customerGroupId = (int)$quote->getCustomerGroupId();
        if ($quote->getCustomerId()) {
            $customerGroupId = (int)($quote->getCustomer()->getGroupId() ?? $customerGroupId);
            $quote->setCustomerGroupId($customerGroupId);
        }

        if (!$quote->getCustomerId()) {
            $quote->setCustomerIsGuest(true);
            $quote->setCheckoutMethod(CartManagementInterface::METHOD_GUEST);
            if (!$quote->getCustomerEmail()) {
                $quote->setCustomerEmail($this->config->getGuestEmail($storeId));
            }
            if (!$quote->getCustomerFirstname()) {
                $quote->setCustomerFirstname('POS');
                $quote->setCustomerLastname('Customer');
            }
        } elseif (!$quote->getCustomerEmail()) {
            $quote->setCustomerEmail($this->config->getGuestEmail($storeId));
        }

        $defaults = $this->getAddressDefaults($storeId);
        $email = (string)$quote->getCustomerEmail();

        $this->fillAddress($quote->getBillingAddress(), $defaults, $email);
        if ($quote->isVirtual()) {
            return;
        }

        $shippingAddress = $quote->getShippingAddress();
        $this->fillAddress($shippingAddress, $defaults, $email);
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->collectShippingRates();
        try {
            $method = $this->pickShippingMethod($shippingAddress);
        } catch (LocalizedException $e) {
            if ($requireShippingMethod) {
                throw $e;
            }
            $shippingAddress->setShippingMethod('');

            return;
        }
        $shippingAddress->setShippingMethod($method);
        foreach ($shippingAddress->getAllShippingRates() as $rate) {
            if ((string)$rate->getCode() === $method) {
                $rate->setPrice(0.0);
            }
        }
    }

    private function getAddressDefaults(int $storeId): array
    {
        $get = fn (string $path): string => trim(
            (string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId)
        );

        $country = $get(StoreInformation::XML_PATH_STORE_INFO_COUNTRY_CODE);
        if ($country === '') {
            $country = (string)$this->directoryHelper->getDefaultCountry($storeId);
        }
        if ($country === '') {
            $country = 'US';
        }
        $regionId = (int)$get(StoreInformation::XML_PATH_STORE_INFO_REGION_CODE);

        return [
            'firstname' => 'POS',
            'lastname' => 'Customer',
            'street' => $get(StoreInformation::XML_PATH_STORE_INFO_STREET_LINE1) ?: 'POS',
            'city' => $get(StoreInformation::XML_PATH_STORE_INFO_CITY) ?: 'POS',
            'postcode' => $get(StoreInformation::XML_PATH_STORE_INFO_POSTCODE) ?: '00000',
            'telephone' => $get(StoreInformation::XML_PATH_STORE_INFO_PHONE) ?: '0000000000',
            'country_id' => $country,
            'region_id' => $regionId,
        ];
    }

    private function fillAddress(QuoteAddress $address, array $defaults, string $email): void
    {
        if (!$address->getFirstname()) {
            $address->setFirstname($defaults['firstname']);
        }
        if (!$address->getLastname()) {
            $address->setLastname($defaults['lastname']);
        }
        if (!$address->getStreetLine(1)) {
            $address->setStreet([$defaults['street']]);
        }
        if (!$address->getCity()) {
            $address->setCity($defaults['city']);
        }
        if (!$address->getTelephone()) {
            $address->setTelephone($defaults['telephone']);
        }
        if (!$address->getCountryId()) {
            $address->setCountryId($defaults['country_id']);
        }
        if (!$address->getPostcode()) {
            $address->setPostcode($defaults['postcode']);
        }
        $countryId = (string)$address->getCountryId();
        if (!$address->getRegionId() && $this->directoryHelper->isRegionRequired($countryId)) {
            $regionId = $defaults['region_id'] ?: $this->getFirstRegionId($countryId);
            if ($regionId > 0) {
                $address->setRegionId($regionId);
            }
        }
        if ($email !== '') {
            $address->setEmail($email);
        }
    }

    private function getFirstRegionId(string $countryId): int
    {
        $collection = $this->regionCollectionFactory->create();
        $collection->addCountryFilter($countryId);
        $collection->setPageSize(1);
        $region = $collection->getFirstItem();

        return (int)$region->getId();
    }

    private function pickShippingMethod(QuoteAddress $shippingAddress): string
    {
        $rates = $shippingAddress->getAllShippingRates();
        if (!$rates) {
            throw new LocalizedException(
                __('No shipping methods are available for the POS order. Enable Free Shipping or Flat Rate.')
            );
        }
        $best = null;
        foreach ($rates as $rate) {
            if ($rate->getCode() === 'freeshipping_freeshipping') {
                return (string)$rate->getCode();
            }
            if ($best === null || (float)$rate->getPrice() < (float)$best->getPrice()) {
                $best = $rate;
            }
        }

        return (string)$best->getCode();
    }
}
