<?php
declare(strict_types=1);

namespace Panth\MagePos\Setup\Patch\Data;

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;

class CreateCustomSaleProduct implements DataPatchInterface
{
    public const CUSTOM_SALE_SKU = 'pos-custom-sale';
    public const CUSTOM_SALE_NAME = 'Custom Sale';

    public function __construct(
        private readonly ProductInterfaceFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState
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
        $this->appState->emulateAreaCode(
            Area::AREA_ADMINHTML,
            function (): void {
                $this->createProduct();
            }
        );
        return $this;
    }

    private function createProduct(): void
    {
        try {
            $this->productRepository->get(self::CUSTOM_SALE_SKU);
            return;
        } catch (NoSuchEntityException) {
        }

        $websiteIds = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteIds[] = (int)$website->getId();
        }

        $product = $this->productFactory->create();
        $product->setSku(self::CUSTOM_SALE_SKU);
        $product->setName(self::CUSTOM_SALE_NAME);
        $product->setTypeId(Type::TYPE_VIRTUAL);
        $product->setAttributeSetId((int)$product->getDefaultAttributeSetId());
        $product->setPrice(0.0);
        $product->setStatus(Status::STATUS_ENABLED);
        $product->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE);
        $product->setWebsiteIds($websiteIds);
        $product->setStoreId(0);
        $product->setStockData([
            'use_config_manage_stock' => 0,
            'manage_stock' => 0,
            'is_in_stock' => 1,
            'qty' => 0,
        ]);

        $this->productRepository->save($product);
    }
}
