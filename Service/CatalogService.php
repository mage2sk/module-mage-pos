<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterface;
use Magento\InventoryApi\Api\GetStockSourceLinksInterface;
use Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\GetStockBySalesChannelInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Swatches\Helper\Data as SwatchHelper;
use Panth\MagePos\Api\Data\QuickKeyInterface;
use Panth\MagePos\Api\Data\QuickKeyInterfaceFactory;
use Panth\MagePos\Api\QuickKeyRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Model\ResourceModel\QuickKey\CollectionFactory as QuickKeyCollectionFactory;

class CatalogService
{
    private const IMAGE_ID = 'product_thumbnail_image';

    private const IMAGE_SIZE = 200;

    private const DETAIL_IMAGE_SIZE = 600;

    private const DETAIL_GALLERY_LIMIT = 8;

    private const DETAIL_ATTRIBUTE_LIMIT = 12;

    private const DETAIL_ATTRIBUTE_EXCLUDE = [
        'name', 'sku', 'description', 'short_description', 'price',
        'special_price', 'image', 'small_image', 'thumbnail', 'swatch_image',
        'media_gallery', 'gallery', 'url_key', 'meta_title', 'meta_keyword',
        'meta_description', 'options_container', 'gift_message_available',
        'msrp_display_actual_price_type', 'quantity_and_stock_status',
        'tax_class_id', 'tier_price', 'category_ids', 'format',
    ];

    private const SEARCH_ATTRIBUTES = ['name', 'sku', 'description', 'short_description'];

    private const SEARCH_TERM_LIMIT = 5;

    private const SEARCH_OPTION_ID_LIMIT = 20;

    private const SEARCH_OPTION_PRODUCT_LIMIT = 500;

    private const LOW_STOCK_THRESHOLD = 5.0;

    private const BESTSELLER_WINDOW_DAYS = 90;

    private const BESTSELLER_RANK_CAP = 120;

    private const SELLABLE_TYPE_IDS = [
        'simple', 'virtual', 'downloadable', 'configurable', 'grouped', 'bundle',
    ];

    private const COMPOSITE_TYPE_IDS = ['configurable', 'grouped', 'bundle'];

    private const VISIBILITY_IDS = [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH];

    private ?bool $msiActive = null;

    private array $stockIdCache = [];

    private array $imageUrlCache = [];

    private ?string $placeholderUrl = null;

    private array $salableQtyCache = [];

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly QuickKeyCollectionFactory $quickKeyCollectionFactory,
        private readonly StockHelper $stockHelper,
        private readonly ImageHelper $imageHelper,
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly ModuleManager $moduleManager,
        private readonly Config $config,
        private readonly ?RegisterRepositoryInterface $registerRepository = null,
        private readonly ?GetProductSalableQtyInterface $getProductSalableQty = null,
        private readonly ?GetStockBySalesChannelInterface $getStockBySalesChannel = null,
        private readonly ?GetStockSourceLinksInterface $getStockSourceLinks = null,
        private readonly ?DefaultStockProviderInterface $defaultStockProvider = null,
        private readonly ?SearchCriteriaBuilder $searchCriteriaBuilder = null,
        private readonly ?SalesChannelInterfaceFactory $salesChannelFactory = null,
        private readonly ?QuickKeyRepositoryInterface $quickKeyRepository = null,
        private readonly ?QuickKeyInterfaceFactory $quickKeyFactory = null,
        private readonly ?SwatchHelper $swatchHelper = null
    ) {
    }

    public function search(string $q, int $storeId, int $page = 1, int $customerGroupId = 0, ?int $registerId = null): array
    {
        $q = trim($q);
        if ($q === '') {
            return $this->pageEnvelope([], 0, 1, $this->config->getSearchPageSize($storeId));
        }

        $collection = $this->createBaseCollection($storeId, $customerGroupId);
        $this->excludeCustomSaleProduct($collection, $storeId);

        $barcodeAttribute = $this->config->getBarcodeAttribute($storeId);
        $hasBarcodeAttribute = !in_array($barcodeAttribute, ['sku', 'name'], true)
            && $this->attributeExists($barcodeAttribute);

        foreach ($this->splitSearchTerms($q) as $term) {
            $like = '%' . $this->escapeLikeValue($term) . '%';
            $conditions = [];
            foreach (self::SEARCH_ATTRIBUTES as $attributeCode) {
                $conditions[] = ['attribute' => $attributeCode, 'like' => $like];
            }

            $optionProductIds = $this->optionMatchedProductIds($term, $storeId);
            if ($optionProductIds !== []) {
                $conditions[] = ['attribute' => 'entity_id', 'in' => $optionProductIds];
            }
            if ($hasBarcodeAttribute) {
                $conditions[] = ['attribute' => $barcodeAttribute, 'eq' => $q];
            }

            $collection->addAttributeToFilter($conditions, null, 'left');
        }
        $collection->addAttributeToSort('name', 'ASC');

        return $this->paginateAndFormat($collection, $page, $storeId, $registerId);
    }

    private function splitSearchTerms(string $q): array
    {
        $terms = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($terms) || $terms === []) {
            return [$q];
        }

        return array_slice(array_values(array_unique($terms)), 0, self::SEARCH_TERM_LIMIT);
    }

    private function optionMatchedProductIds(string $term, int $storeId): array
    {
        $like = '%' . $this->escapeLikeValue($term) . '%';
        $connection = $this->resourceConnection->getConnection();

        $optionSelect = $connection->select()
            ->from(
                ['eaov' => $this->resourceConnection->getTableName('eav_attribute_option_value')],
                []
            )
            ->join(
                ['eao' => $this->resourceConnection->getTableName('eav_attribute_option')],
                'eao.option_id = eaov.option_id',
                []
            )
            ->join(
                ['ea' => $this->resourceConnection->getTableName('eav_attribute')],
                'ea.attribute_id = eao.attribute_id',
                []
            )
            ->join(
                ['cea' => $this->resourceConnection->getTableName('catalog_eav_attribute')],
                'cea.attribute_id = ea.attribute_id',
                []
            )
            ->join(
                ['eet' => $this->resourceConnection->getTableName('eav_entity_type')],
                'eet.entity_type_id = ea.entity_type_id',
                []
            )
            ->columns([
                'attribute_id' => 'ea.attribute_id',
                'input' => 'ea.frontend_input',
                'backend_type' => 'ea.backend_type',
                'option_id' => 'eao.option_id',
            ])
            ->distinct(true)
            ->where('eet.entity_type_code = ?', Product::ENTITY)
            ->where(
                'cea.is_searchable = 1 OR cea.is_filterable > 0'
                . ' OR cea.is_filterable_in_search = 1 OR cea.is_visible_in_advanced_search = 1'
            )
            ->where('ea.frontend_input IN (?)', ['select', 'multiselect'])
            ->where('eaov.store_id IN (?)', [0, $storeId])
            ->where('eaov.value LIKE ?', $like)
            ->limit(self::SEARCH_OPTION_ID_LIMIT);

        try {
            $optionRows = $connection->fetchAll($optionSelect);
        } catch (\Exception $e) {
            return [];
        }
        if ($optionRows === []) {
            return [];
        }

        $byAttribute = [];
        foreach ($optionRows as $row) {
            $attributeId = (int)$row['attribute_id'];
            $optionId = (int)$row['option_id'];
            $backendType = (string)$row['backend_type'];
            if ($attributeId <= 0 || $optionId <= 0
                || !in_array($backendType, ['int', 'varchar', 'text'], true)
            ) {
                continue;
            }
            $byAttribute[$attributeId]['multi'] = ($row['input'] === 'multiselect');
            $byAttribute[$attributeId]['backend'] = $backendType;
            $byAttribute[$attributeId]['option_ids'][$optionId] = $optionId;
        }

        $entityIds = [];
        foreach ($byAttribute as $attributeId => $info) {
            $optionIds = array_values($info['option_ids']);
            $table = 'catalog_product_entity_' . $info['backend'];
            $valueSelect = $connection->select()
                ->from($this->resourceConnection->getTableName($table), ['entity_id'])
                ->distinct(true)
                ->where('attribute_id = ?', $attributeId)
                ->where('store_id IN (?)', [0, $storeId])
                ->limit(self::SEARCH_OPTION_PRODUCT_LIMIT);
            if ($info['multi']) {
                $finset = [];
                foreach ($optionIds as $optionId) {
                    $finset[] = $connection->prepareSqlCondition('value', ['finset' => (string)$optionId]);
                }
                $valueSelect->where(implode(' OR ', $finset));
            } else {
                $valueSelect->where('value IN (?)', $optionIds);
            }
            try {
                foreach ($connection->fetchCol($valueSelect) as $entityId) {
                    $entityIds[(int)$entityId] = (int)$entityId;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        if ($entityIds === []) {
            return [];
        }
        $entityIds = array_slice(array_values($entityIds), 0, self::SEARCH_OPTION_PRODUCT_LIMIT);

        $parentSelect = $connection->select()
            ->from($this->resourceConnection->getTableName('catalog_product_relation'), ['parent_id'])
            ->distinct(true)
            ->where('child_id IN (?)', $entityIds)
            ->limit(self::SEARCH_OPTION_PRODUCT_LIMIT);
        try {
            foreach ($connection->fetchCol($parentSelect) as $parentId) {
                $entityIds[] = (int)$parentId;
            }
        } catch (\Exception $e) {
            unset($e);
        }

        return array_slice(array_values(array_unique($entityIds)), 0, 2 * self::SEARCH_OPTION_PRODUCT_LIMIT);
    }

    public function byBarcode(string $code, int $storeId, int $customerGroupId = 0, ?int $registerId = null): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $attribute = $this->config->getBarcodeAttribute($storeId);
        if ($attribute !== 'sku' && !$this->attributeExists($attribute)) {
            $attribute = 'sku';
        }

        $collection = $this->createBaseCollection($storeId, $customerGroupId);
        $collection->addAttributeToFilter($attribute, $code);
        $collection->setPageSize(1)->setCurPage(1);

        $product = $collection->getFirstItem();
        if ($product === null || !$product->getId()) {
            return null;
        }

        return $this->formatProduct($product, $storeId, $registerId);
    }

    public function categoryTree(int $storeId): array
    {
        $store = $this->storeManager->getStore($storeId);
        $rootCategoryId = (int)$store->getRootCategoryId();
        if ($rootCategoryId <= 0) {
            return [];
        }

        try {
            $root = $this->categoryRepository->get($rootCategoryId, $storeId);
        } catch (NoSuchEntityException $e) {
            return [];
        }
        $rootPath = (string)$root->getPath();
        $rootLevel = (int)$root->getLevel();

        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect('name')
            ->addIsActiveFilter()
            ->addFieldToFilter('path', ['like' => $rootPath . '/%'])
            ->addFieldToFilter('level', ['lteq' => $rootLevel + 2])
            ->setOrder('level', 'ASC')
            ->setOrder('position', 'ASC');

        $nodes = [];
        foreach ($collection as $category) {
            $nodes[(int)$category->getId()] = [
                'id' => (int)$category->getId(),
                'name' => (string)$category->getName(),
                'parent_id' => (int)$category->getParentId(),
                'children' => [],
            ];
        }

        $tree = [];
        foreach ($nodes as $id => &$node) {
            $parentId = $node['parent_id'];
            if ($parentId === $rootCategoryId) {
                $tree[] = &$node;
            } elseif (isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$node;
            }
            unset($node);
        }

        return $tree;
    }

    public function byCategory(int $categoryId, int $storeId, int $page, int $customerGroupId = 0, ?int $registerId = null): array
    {
        $category = $this->categoryRepository->get($categoryId, $storeId);

        $collection = $this->createBaseCollection($storeId, $customerGroupId);
        $this->excludeCustomSaleProduct($collection, $storeId);
        $collection->addCategoriesFilter(['in' => $this->getCategoryBranchIds($category, $storeId)]);
        $collection->getSelect()->distinct(true);
        $collection->addAttributeToSort('name', 'ASC');

        return $this->paginateAndFormat($collection, $page, $storeId, $registerId);
    }

    public function bestSellers(int $storeId, int $limit = 24, int $customerGroupId = 0, ?int $registerId = null, int $page = 1): array
    {
        $pageSize = max(1, min(100, $limit));
        $page = max(1, $page);

        $rankedIds = $this->rankBestSellerProductIds($storeId, self::BESTSELLER_RANK_CAP);

        if ($rankedIds !== []) {
            $idCollection = $this->createBaseCollection($storeId, $customerGroupId);
            $this->excludeCustomSaleProduct($idCollection, $storeId);
            $idCollection->addAttributeToFilter('entity_id', ['in' => $rankedIds]);
            $sellableMap = array_flip(array_map('intval', $idCollection->getAllIds()));

            $orderedIds = [];
            foreach ($rankedIds as $id) {
                if (isset($sellableMap[$id])) {
                    $orderedIds[] = $id;
                }
            }

            if ($orderedIds !== []) {
                $totalCount = count($orderedIds);
                $pageIds = array_slice($orderedIds, ($page - 1) * $pageSize, $pageSize);
                if ($pageIds === []) {
                    return $this->pageEnvelope([], $totalCount, $page, $pageSize);
                }

                $collection = $this->createBaseCollection($storeId, $customerGroupId);
                $collection->addAttributeToFilter('entity_id', ['in' => $pageIds]);
                $byId = [];
                foreach ($collection as $product) {
                    $byId[(int)$product->getId()] = $product;
                }

                $items = [];
                foreach ($pageIds as $id) {
                    if (isset($byId[$id])) {
                        $items[] = $this->formatProduct($byId[$id], $storeId, $registerId);
                    }
                }

                return $this->pageEnvelope($items, $totalCount, $page, $pageSize);
            }
        }

        $collection = $this->createBaseCollection($storeId, $customerGroupId);
        $this->excludeCustomSaleProduct($collection, $storeId);
        $collection->setOrder('entity_id', 'DESC');

        return $this->paginateAndFormat($collection, $page, $storeId, $registerId, $pageSize);
    }

    private function rankBestSellerProductIds(int $storeId, int $limit): array
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException $e) {
            return [];
        }

        $storeIds = [0, $storeId];
        try {
            foreach ($this->storeManager->getWebsite($store->getWebsiteId())->getStoreIds() as $sid) {
                $storeIds[] = (int)$sid;
            }
        } catch (\Exception $e) {
            unset($e);
        }
        $storeIds = array_values(array_unique(array_map('intval', $storeIds)));

        $connection = $this->resourceConnection->getConnection();
        $since = gmdate('Y-m-d H:i:s', strtotime('-' . self::BESTSELLER_WINDOW_DAYS . ' days'));

        $select = $connection->select()
            ->from(
                ['soi' => $this->resourceConnection->getTableName('sales_order_item')],
                ['product_id' => 'soi.product_id', 'ordered' => new \Zend_Db_Expr('SUM(soi.qty_ordered)')]
            )
            ->where('soi.product_id IS NOT NULL')
            ->where('soi.parent_item_id IS NULL')
            ->where('soi.store_id IN (?)', $storeIds)
            ->where('soi.created_at >= ?', $since)
            ->group('soi.product_id')
            ->order('ordered DESC')
            ->limit($limit);

        try {
            $rows = $connection->fetchAll($select);
        } catch (\Exception $e) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (int)$row['product_id'];
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function getCategoryBranchIds(CategoryInterface $category, int $storeId): array
    {
        $ids = [(int)$category->getId()];

        $path = (string)$category->getPath();
        if ($path !== '') {
            $descendants = $this->categoryCollectionFactory->create();
            $descendants->setStoreId($storeId)
                ->addIsActiveFilter()
                ->addFieldToFilter('path', ['like' => $path . '/%']);
            foreach ($descendants->getAllIds() as $descendantId) {
                $ids[] = (int)$descendantId;
            }
        }

        return array_values(array_unique($ids));
    }

    public function quickKeys(?int $registerId, int $customerGroupId = 0): array
    {
        $quickKeys = $this->quickKeyCollectionFactory->create()->addRegisterFilter($registerId);

        $productIds = [];
        foreach ($quickKeys as $quickKey) {
            $productIds[] = (int)$quickKey->getProductId();
        }
        if ($productIds === []) {
            return [];
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        $collection = $this->createBaseCollection($storeId, $customerGroupId);
        $collection->addAttributeToFilter('entity_id', ['in' => array_unique($productIds)]);

        $products = [];
        foreach ($collection as $product) {
            $products[(int)$product->getId()] = $this->formatProduct($product, $storeId, $registerId);
        }

        $tiles = [];
        foreach ($quickKeys as $quickKey) {
            $productId = (int)$quickKey->getProductId();
            if (!isset($products[$productId])) {
                continue;
            }
            $product = $products[$productId];
            $label = $quickKey->getLabel();
            $tiles[] = array_merge($product, [
                'quick_key_id' => (int)$quickKey->getQuickKeyId(),
                'product_id' => $productId,
                'label' => $label !== null && $label !== '' ? $label : $product['name'],
                'color' => $quickKey->getColor(),
                'position' => (int)$quickKey->getPosition(),
                'page' => (int)$quickKey->getPage(),
            ]);
        }

        return $tiles;
    }

    public function quickKeySave(array $input, ?int $registerId): array
    {
        if ($this->quickKeyRepository === null || $this->quickKeyFactory === null) {
            throw new LocalizedException(__('Quick keys are not available.'));
        }

        $quickKeyId = isset($input['quick_key_id']) && is_numeric($input['quick_key_id'])
            ? (int)$input['quick_key_id']
            : 0;
        $label = isset($input['label']) && is_string($input['label'])
            ? mb_substr(trim($input['label']), 0, 64)
            : '';
        $color = isset($input['color']) && is_string($input['color']) ? trim($input['color']) : '';
        if ($color !== '' && !preg_match('/^#[0-9a-f]{3,8}$/i', $color)) {
            $color = '';
        }
        $page = isset($input['page']) && is_numeric($input['page']) ? max(1, (int)$input['page']) : null;
        $position = isset($input['position']) && is_numeric($input['position'])
            ? max(0, (int)$input['position'])
            : null;

        if ($quickKeyId > 0) {
            try {
                $quickKey = $this->quickKeyRepository->getById($quickKeyId);
            } catch (NoSuchEntityException $e) {
                throw new LocalizedException(__('Quick key not found.'));
            }
            $this->assertQuickKeyScope($quickKey, $registerId);
            if ($label !== '') {
                $quickKey->setLabel($label);
            }
            if ($color !== '') {
                $quickKey->setColor($color);
            }
            if ($page !== null) {
                $quickKey->setPage($page);
            }
            if ($position !== null) {
                $quickKey->setPosition($position);
            }

            return $this->formatQuickKeyRow($this->quickKeyRepository->save($quickKey));
        }

        $productId = isset($input['product_id']) && is_numeric($input['product_id'])
            ? (int)$input['product_id']
            : 0;
        if ($productId <= 0) {
            throw new LocalizedException(__('A product is required to pin a quick key.'));
        }

        $existing = $this->quickKeyCollectionFactory->create()->addRegisterFilter($registerId);
        $existing->addFieldToFilter('product_id', $productId);
        $existing->setPageSize(1)->setCurPage(1);

        $first = $existing->getFirstItem();
        if ($first !== null && $first->getQuickKeyId()) {
            return $this->formatQuickKeyRow($first);
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        $collection = $this->createBaseCollection($storeId);
        $collection->addAttributeToFilter('entity_id', $productId);
        $collection->setPageSize(1)->setCurPage(1);
        $product = $collection->getFirstItem();
        if ($product === null || !$product->getId()) {
            throw new LocalizedException(__('This product cannot be pinned - it is not sellable on this store.'));
        }

        [$resolvedPage, $nextPosition] = $this->nextQuickKeyPlacement($registerId, $page);

        $quickKey = $this->quickKeyFactory->create();
        $quickKey->setRegisterId($registerId);
        $quickKey->setProductId($productId);
        $quickKey->setLabel($label !== '' ? $label : null);
        $quickKey->setColor($color !== '' ? $color : null);
        $quickKey->setPage($resolvedPage);
        $quickKey->setPosition($position ?? $nextPosition);

        return $this->formatQuickKeyRow($this->quickKeyRepository->save($quickKey));
    }

    public function quickKeyRemove(int $quickKeyId, ?int $registerId): void
    {
        if ($this->quickKeyRepository === null) {
            throw new LocalizedException(__('Quick keys are not available.'));
        }
        if ($quickKeyId <= 0) {
            throw new LocalizedException(__('Quick key not found.'));
        }

        try {
            $quickKey = $this->quickKeyRepository->getById($quickKeyId);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Quick key not found.'));
        }
        $this->assertQuickKeyScope($quickKey, $registerId);

        $this->quickKeyRepository->delete($quickKey);
    }

    public function productDetail(int $productId, int $storeId, int $customerGroupId = 0, ?int $registerId = null): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $collection = $this->createBaseCollection($storeId, $customerGroupId);
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('entity_id', $productId);
        $collection->setPageSize(1)->setCurPage(1);
        $collection->load();
        try {
            $collection->addMediaGalleryData();
        } catch (\Throwable $e) {
            unset($e);
        }

        $product = $collection->getFirstItem();
        if ($product === null || !$product->getId()) {
            return null;
        }

        $row = $this->formatProduct($product, $storeId, $registerId);
        $row['short_description'] = $this->sanitizeHtml((string)$product->getData('short_description'));
        $row['description'] = $this->sanitizeHtml((string)$product->getData('description'));
        if ($row['short_description'] === $row['description']) {
            $row['short_description'] = '';
        }
        $row['gallery'] = $this->getGalleryUrls($product);
        $row['attributes'] = $this->getFrontendAttributes($product);

        return $row;
    }

    public function productOptions(int $productId, int $storeId, int $customerGroupId = 0, ?int $registerId = null): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $collection = $this->createBaseCollection($storeId, $customerGroupId);
        $collection->addAttributeToFilter('entity_id', $productId);
        $collection->setPageSize(1)->setCurPage(1);
        $collection->load();
        try {
            $collection->addOptionsToResult();
        } catch (\Throwable $e) {
            unset($e);
        }

        $product = $collection->getFirstItem();
        if ($product === null || !$product->getId()) {
            return null;
        }

        $row = $this->formatProduct($product, $storeId, $registerId);
        $customOptions = $this->getCustomOptionsPayload($product);
        if ($customOptions !== []) {
            $row['has_options'] = true;
            foreach ($customOptions as $customOption) {
                if (!empty($customOption['required'])) {
                    $row['requires_options'] = true;
                    break;
                }
            }
        }
        $payload = [
            'product' => $row,
            'type' => $row['type'],
            'custom_options' => $customOptions,
        ];

        switch ($row['type']) {
            case 'configurable':
                $payload['super_attributes'] = $this->getConfigurableAttributesPayload($product);
                $payload['children'] = $this->getConfigurableChildrenPayload(
                    $product,
                    $storeId,
                    $customerGroupId,
                    $registerId
                );
                break;
            case 'grouped':
                $payload['associated'] = $this->getGroupedAssociatedPayload(
                    $product,
                    $storeId,
                    $customerGroupId,
                    $registerId
                );
                break;
            case 'bundle':
                $payload['bundle_options'] = $this->getBundleOptionsPayload(
                    $product,
                    $storeId,
                    $customerGroupId,
                    $registerId
                );
                break;
        }

        return $payload;
    }

    private function getConfigurableAttributesPayload(Product $product): array
    {
        $typeInstance = $product->getTypeInstance();
        if (!$typeInstance instanceof \Magento\ConfigurableProduct\Model\Product\Type\Configurable) {
            return [];
        }

        try {
            $attributes = $typeInstance->getConfigurableAttributesAsArray($product);
        } catch (\Throwable $e) {
            return [];
        }

        $valueIds = [];
        foreach ($attributes as $attribute) {
            foreach ($attribute['values'] ?? [] as $value) {
                if (isset($value['value_index'])) {
                    $valueIds[] = (int)$value['value_index'];
                }
            }
        }
        $swatches = $this->getSwatchMap($valueIds);

        $rows = [];
        foreach ($attributes as $attribute) {
            $values = [];
            foreach ($attribute['values'] ?? [] as $value) {
                if (!isset($value['value_index'])) {
                    continue;
                }
                $valueId = (int)$value['value_index'];
                $valueRow = [
                    'value_id' => $valueId,
                    'label' => (string)($value['label'] ?? $value['default_label'] ?? ''),
                ];
                if (isset($swatches[$valueId])) {
                    $valueRow['swatch'] = $swatches[$valueId];
                }
                $values[] = $valueRow;
            }
            $rows[] = [
                'attribute_id' => (int)$attribute['attribute_id'],
                'label' => (string)($attribute['store_label']
                    ?? $attribute['label']
                    ?? $attribute['frontend_label']
                    ?? ''),
                'code' => (string)($attribute['attribute_code'] ?? ''),
                'position' => (int)($attribute['position'] ?? 0),
                'values' => $values,
            ];
        }
        usort($rows, static fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $rows;
    }

    private function getSwatchMap(array $valueIds): array
    {
        if ($this->swatchHelper === null || $valueIds === []) {
            return [];
        }
        try {
            $swatches = $this->swatchHelper->getSwatchesByOptionsId($valueIds);
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];
        foreach ($swatches as $optionId => $swatch) {
            $value = isset($swatch['value']) ? (string)$swatch['value'] : '';
            if ($value === '') {
                continue;
            }
            $map[(int)$optionId] = [
                'type' => (int)($swatch['type'] ?? 0),
                'value' => $value,
            ];
        }

        return $map;
    }

    private function getConfigurableChildrenPayload(
        Product $product,
        int $storeId,
        int $customerGroupId,
        ?int $registerId
    ): array {
        $typeInstance = $product->getTypeInstance();
        if (!$typeInstance instanceof \Magento\ConfigurableProduct\Model\Product\Type\Configurable) {
            return [];
        }

        try {
            $attributes = $typeInstance->getConfigurableAttributesAsArray($product);
            $usedProducts = $typeInstance->getUsedProducts($product);
        } catch (\Throwable $e) {
            return [];
        }

        $codesByAttributeId = [];
        foreach ($attributes as $attribute) {
            $code = (string)($attribute['attribute_code'] ?? '');
            if ($code !== '') {
                $codesByAttributeId[(int)$attribute['attribute_id']] = $code;
            }
        }
        if ($codesByAttributeId === []) {
            return [];
        }

        $childIds = [];
        foreach ($usedProducts as $child) {
            $childIds[] = (int)$child->getId();
        }
        if ($childIds === []) {
            return [];
        }

        $children = $this->createPricedProductCollection(
            $childIds,
            $storeId,
            $customerGroupId,
            array_values($codesByAttributeId)
        );

        $rows = [];
        foreach ($children as $child) {
            $superAttribute = [];
            $complete = true;
            foreach ($codesByAttributeId as $attributeId => $code) {
                $valueId = $child->getData($code);
                if ($valueId === null || $valueId === '') {
                    $complete = false;
                    break;
                }
                $superAttribute[(string)$attributeId] = (int)$valueId;
            }
            if (!$complete) {
                continue;
            }

            [$finalPrice] = $this->resolvePrices($child);
            $salableQty = $this->resolveSalableQty($child, $storeId, $registerId);

            $rows[] = [
                'id' => (int)$child->getId(),
                'sku' => (string)$child->getSku(),
                'super_attribute' => $superAttribute,
                'price' => round($finalPrice, 4),
                'salable_qty' => $salableQty === null ? null : round($salableQty, 4),
                'in_stock' => $salableQty === null ? true : $salableQty > 0,
            ];
        }

        return $rows;
    }

    private function getGroupedAssociatedPayload(
        Product $product,
        int $storeId,
        int $customerGroupId,
        ?int $registerId
    ): array {
        $typeInstance = $product->getTypeInstance();
        if (!$typeInstance instanceof \Magento\GroupedProduct\Model\Product\Type\Grouped) {
            return [];
        }

        try {
            $associated = $typeInstance->getAssociatedProducts($product);
        } catch (\Throwable $e) {
            return [];
        }

        $orderedIds = [];
        $defaultQty = [];
        foreach ($associated as $child) {
            $childId = (int)$child->getId();
            $orderedIds[] = $childId;
            $qty = $child->getQty();
            $defaultQty[$childId] = ($qty === null || $qty === '') ? 0.0 : max(0.0, (float)$qty);
        }
        if ($orderedIds === []) {
            return [];
        }

        $pricedById = [];
        foreach ($this->createPricedProductCollection($orderedIds, $storeId, $customerGroupId, ['name']) as $child) {
            $pricedById[(int)$child->getId()] = $child;
        }

        $rows = [];
        foreach ($orderedIds as $childId) {
            if (!isset($pricedById[$childId])) {
                continue;
            }
            $child = $pricedById[$childId];
            [$finalPrice] = $this->resolvePrices($child);
            $salableQty = $this->resolveSalableQty($child, $storeId, $registerId);

            $rows[] = [
                'id' => $childId,
                'sku' => (string)$child->getSku(),
                'name' => (string)$child->getName(),
                'price' => round($finalPrice, 4),
                'qty_default' => $defaultQty[$childId] ?? 0.0,
                'salable_qty' => $salableQty === null ? null : round($salableQty, 4),
                'in_stock' => $salableQty === null ? true : $salableQty > 0,
            ];
        }

        return $rows;
    }

    private function getBundleOptionsPayload(
        Product $product,
        int $storeId,
        int $customerGroupId,
        ?int $registerId
    ): array {
        $typeInstance = $product->getTypeInstance();
        if (!$typeInstance instanceof \Magento\Bundle\Model\Product\Type) {
            return [];
        }

        try {
            $optionsCollection = $typeInstance->getOptionsCollection($product);
            $selectionsCollection = $typeInstance->getSelectionsCollection(
                $typeInstance->getOptionsIds($product),
                $product
            );
        } catch (\Throwable $e) {
            return [];
        }

        $isFixedPrice = (int)$product->getPriceType() === \Magento\Bundle\Model\Product\Price::PRICE_TYPE_FIXED;

        $fixedBase = $isFixedPrice ? (float)$product->getPrice() : 0.0;
        if ($isFixedPrice) {
            $specialPrice = $product->getData('special_price');
            if ($specialPrice !== null && $specialPrice !== '' && (float)$specialPrice > 0
                && (float)$specialPrice < $fixedBase
            ) {
                $fixedBase = (float)$specialPrice;
            }
        }

        $selectionProductIds = [];
        foreach ($selectionsCollection as $selection) {
            $selectionProductIds[] = (int)$selection->getProductId();
        }
        $pricedById = [];
        if ($selectionProductIds !== []) {
            foreach ($this->createPricedProductCollection(
                array_values(array_unique($selectionProductIds)),
                $storeId,
                $customerGroupId,
                ['name']
            ) as $child) {
                $pricedById[(int)$child->getId()] = $child;
            }
        }

        $selectionsByOption = [];
        foreach ($selectionsCollection as $selection) {
            $optionId = (int)$selection->getOptionId();
            $selectionProductId = (int)$selection->getProductId();
            if (!isset($pricedById[$selectionProductId])) {
                continue;
            }

            $salableQty = $this->resolveSalableQty($pricedById[$selectionProductId], $storeId, $registerId);
            $inStock = $salableQty === null ? true : $salableQty > 0;
            if ($isFixedPrice) {
                $price = (int)$selection->getSelectionPriceType() === 1
                    ? round($fixedBase * (float)$selection->getSelectionPriceValue() / 100, 4)
                    : round((float)$selection->getSelectionPriceValue(), 4);
            } else {
                [$childFinal] = $this->resolvePrices($pricedById[$selectionProductId]);
                $price = round($childFinal, 4);
            }

            $selectionsByOption[$optionId][] = [
                'selection_id' => (int)$selection->getSelectionId(),
                'product_id' => $selectionProductId,
                'sku' => (string)$selection->getSku(),
                'name' => (string)$selection->getName(),
                'price' => $price,
                'qty' => max(1.0, (float)$selection->getSelectionQty()),
                'can_change_qty' => (bool)$selection->getSelectionCanChangeQty(),
                'is_default' => (bool)$selection->getIsDefault(),
                'in_stock' => $inStock,
            ];
        }

        $rows = [];
        foreach ($optionsCollection as $option) {
            $optionId = (int)($option->getOptionId() ?: $option->getId());
            if (!isset($selectionsByOption[$optionId])) {
                continue;
            }
            $title = (string)$option->getTitle();
            if ($title === '') {
                $title = (string)$option->getDefaultTitle();
            }
            $rows[] = [
                'option_id' => $optionId,
                'title' => $title,
                'type' => (string)$option->getType(),
                'required' => (bool)$option->getRequired(),
                'position' => (int)$option->getPosition(),
                'selections' => $selectionsByOption[$optionId],
            ];
        }
        usort($rows, static fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $rows;
    }

    private function getCustomOptionsPayload(Product $product): array
    {
        try {
            $options = $product->getOptions();
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($options) || $options === []) {
            return [];
        }

        $rows = [];
        foreach ($options as $option) {
            if (!is_object($option)) {
                continue;
            }
            $row = [
                'option_id' => (int)$option->getId(),
                'title' => (string)$option->getTitle(),
                'type' => (string)$option->getType(),
                'required' => (bool)$option->getIsRequire(),
                'price' => round((float)$option->getPrice(), 4),
                'price_type' => (string)($option->getPriceType() ?: 'fixed'),
                'sort_order' => (int)$option->getSortOrder(),
            ];
            $maxCharacters = $option->getData('max_characters');
            if ($maxCharacters !== null && (int)$maxCharacters > 0) {
                $row['max_characters'] = (int)$maxCharacters;
            }
            $fileExtension = trim((string)$option->getData('file_extension'));
            if ($fileExtension !== '') {
                $row['file_extension'] = $fileExtension;
            }

            $values = $option->getValues();
            if (is_array($values) && $values !== []) {
                $valueRows = [];
                foreach ($values as $value) {
                    $valueRows[] = [
                        'value_id' => (int)$value->getOptionTypeId(),
                        'title' => (string)$value->getTitle(),
                        'price' => round((float)$value->getPrice(), 4),
                        'price_type' => (string)($value->getPriceType() ?: 'fixed'),
                        'sort_order' => (int)$value->getSortOrder(),
                    ];
                }
                usort($valueRows, static fn (array $a, array $b) => $a['sort_order'] <=> $b['sort_order']);
                $row['values'] = $valueRows;
            }

            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b) => $a['sort_order'] <=> $b['sort_order']);

        return $rows;
    }

    private function createPricedProductCollection(
        array $productIds,
        int $storeId,
        int $customerGroupId,
        array $extraAttributes = []
    ): ProductCollection {
        $collection = $this->productCollectionFactory->create();
        $collection->setStore($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToSelect(array_values(array_unique(array_merge(['name'], $extraAttributes))))
            ->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED)
            ->addAttributeToFilter('entity_id', ['in' => $productIds])
            ->addPriceData(max(0, $customerGroupId), $this->resolveWebsiteId($storeId));

        return $collection;
    }

    private function formatQuickKeyRow(QuickKeyInterface $quickKey): array
    {
        return [
            'quick_key_id' => (int)$quickKey->getQuickKeyId(),
            'register_id' => $quickKey->getRegisterId(),
            'product_id' => (int)$quickKey->getProductId(),
            'label' => $quickKey->getLabel(),
            'color' => $quickKey->getColor(),
            'page' => (int)$quickKey->getPage(),
            'position' => (int)$quickKey->getPosition(),
        ];
    }

    private function assertQuickKeyScope(QuickKeyInterface $quickKey, ?int $registerId): void
    {
        $owner = $quickKey->getRegisterId();
        if ($owner !== null && $owner !== $registerId) {
            throw new LocalizedException(__('This quick key belongs to another register.'));
        }
    }

    private function nextQuickKeyPlacement(?int $registerId, ?int $page): array
    {
        $collection = $this->quickKeyCollectionFactory->create()->addRegisterFilter($registerId);

        $maxPage = 1;
        foreach ($collection as $quickKey) {
            $maxPage = max($maxPage, max(1, (int)$quickKey->getPage()));
        }
        $page = $page ?? $maxPage;

        $maxPosition = -1;
        foreach ($collection as $quickKey) {
            if (max(1, (int)$quickKey->getPage()) === $page) {
                $maxPosition = max($maxPosition, (int)$quickKey->getPosition());
            }
        }

        return [$page, $maxPosition + 1];
    }

    private function getGalleryUrls(Product $product): array
    {
        $urls = [];
        try {
            $images = $product->getMediaGalleryImages();
            if ($images) {
                foreach ($images as $image) {
                    $file = (string)$image->getFile();
                    if ($file === '') {
                        continue;
                    }
                    try {
                        $urls[] = $this->imageHelper
                            ->init($product, self::IMAGE_ID)
                            ->setImageFile($file)
                            ->resize(self::DETAIL_IMAGE_SIZE)
                            ->getUrl();
                    } catch (\Exception $e) {
                        continue;
                    }
                    if (count($urls) >= self::DETAIL_GALLERY_LIMIT) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $urls = [];
        }

        if ($urls === []) {
            $urls[] = $this->getImageUrl($product);
        }

        return $urls;
    }

    private function getFrontendAttributes(Product $product): array
    {
        $rows = [];

        foreach ($product->getAttributes() as $attribute) {
            if (count($rows) >= self::DETAIL_ATTRIBUTE_LIMIT) {
                break;
            }
            $code = (string)$attribute->getAttributeCode();
            if (in_array($code, self::DETAIL_ATTRIBUTE_EXCLUDE, true)) {
                continue;
            }
            $isVisibleOnFront = (bool)$attribute->getIsVisibleOnFront();
            if (!$isVisibleOnFront && !$attribute->getIsUserDefined()) {
                continue;
            }

            if (!$isVisibleOnFront && $attribute->getFrontendInput() === 'boolean') {
                continue;
            }
            $label = trim((string)$attribute->getStoreLabel());
            if ($label === '') {
                continue;
            }

            $raw = $product->getData($code);
            if ($raw === null || $raw === '' || $raw === false || $raw === []) {
                continue;
            }
            try {
                $value = $attribute->getFrontend()->getValue($product);
            } catch (\Throwable $e) {
                continue;
            }
            if ($value instanceof \Magento\Framework\Phrase) {
                $value = (string)$value;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value === '' || $value === 'N/A' || $value === 'No') {
                continue;
            }
            $rows[] = [
                'code' => $code,
                'label' => $label,
                'value' => $value,
            ];
        }

        return $rows;
    }

    private function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = (string)preg_replace('/\{\{[^}]*\}\}/s', '', $html);

        $html = strip_tags(
            $html,
            '<p><br><hr><ul><ol><li><strong><b><em><i><u><s><small><sup><sub>'
            . '<span><div><h1><h2><h3><h4><h5><h6><table><thead><tbody><tfoot>'
            . '<tr><th><td><blockquote><a><img><figure><figcaption><dl><dt><dd>'
        );

        $html = (string)preg_replace(
            '/(?<=[\s\/"\'])on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            '',
            $html
        );

        $html = (string)preg_replace_callback(
            '/(?<=[\s\/"\'])(href|src|xlink:href|action|formaction)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            static function (array $match): string {
                $value = html_entity_decode(trim($match[2], '"\''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $value = strtolower((string)preg_replace('/[\x00-\x20]+/', '', $value));
                if (preg_match('/^(javascript|vbscript):|^data:(?!image\/(png|gif|jpe?g|webp)[;,])/', $value) === 1) {
                    return '';
                }

                return $match[0];
            },
            $html
        );

        $html = (string)preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html);

        return trim($html);
    }

    public function catalogSnapshot(int $storeId, int $customerGroupId = 0, ?int $registerId = null): array
    {
        $limit = $this->config->getCatalogCacheLimit($storeId);

        $collection = $this->createBaseCollection($storeId, $customerGroupId);
        $this->excludeCustomSaleProduct($collection, $storeId);

        $barcodeAttribute = $this->config->getBarcodeAttribute($storeId);
        $hasBarcodeAttribute = !in_array($barcodeAttribute, ['sku', 'name'], true)
            && $this->attributeExists($barcodeAttribute);
        if ($hasBarcodeAttribute) {
            $collection->addAttributeToSelect($barcodeAttribute);
        }

        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize($limit)->setCurPage(1);

        $items = [];
        foreach ($collection as $product) {
            $row = $this->formatProduct($product, $storeId, $registerId);
            $barcode = $hasBarcodeAttribute ? (string)$product->getData($barcodeAttribute) : '';
            $row['barcode'] = $barcode !== '' ? $barcode : $row['sku'];
            $items[] = $row;
        }

        return $items;
    }

    private function createBaseCollection(int $storeId, int $customerGroupId = 0): ProductCollection
    {
        $websiteId = $this->resolveWebsiteId($storeId);

        $collection = $this->productCollectionFactory->create();
        $collection->setStore($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToSelect(['name', 'image', 'small_image', 'thumbnail', 'special_price'])
            ->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => self::VISIBILITY_IDS])
            ->addAttributeToFilter('type_id', ['in' => self::SELLABLE_TYPE_IDS])
            ->addPriceData(max(0, $customerGroupId), $websiteId);

        if (!$this->config->isShowOutOfStock($storeId)) {
            $this->stockHelper->addInStockFilterToCollection($collection);
        }

        return $collection;
    }

    private function resolveWebsiteId(int $storeId): int
    {
        try {
            return (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (NoSuchEntityException $e) {
            return (int)$this->storeManager->getStore()->getWebsiteId();
        }
    }

    private function excludeCustomSaleProduct(ProductCollection $collection, int $storeId): void
    {
        $customSku = $this->config->getCustomProductSku($storeId);
        if ($customSku !== '') {
            $collection->addAttributeToFilter('sku', ['neq' => $customSku]);
        }
    }

    private function paginateAndFormat(
        ProductCollection $collection,
        int $page,
        int $storeId,
        ?int $registerId,
        ?int $pageSize = null
    ): array {
        $page = max(1, $page);
        $pageSize = $pageSize ?? $this->config->getSearchPageSize($storeId);
        $totalCount = (int)$collection->getSize();

        if ($totalCount === 0 || ($page - 1) * $pageSize >= $totalCount) {
            return $this->pageEnvelope([], $totalCount, $page, $pageSize);
        }

        $collection->setPageSize($pageSize)->setCurPage($page);

        $items = [];
        foreach ($collection as $product) {
            $items[] = $this->formatProduct($product, $storeId, $registerId);
        }

        return $this->pageEnvelope($items, $totalCount, $page, $pageSize);
    }

    private function pageEnvelope(array $items, int $totalCount, int $page, int $pageSize): array
    {
        return [
            'items' => $items,
            'total_count' => $totalCount,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    private function formatProduct(Product $product, int $storeId, ?int $registerId = null): array
    {
        $typeId = (string)$product->getTypeId();
        $isComposite = in_array($typeId, self::COMPOSITE_TYPE_IDS, true);

        [$finalPrice, $originalPrice] = $this->resolvePrices($product, $isComposite);

        $salableQty = $isComposite ? null : $this->resolveSalableQty($product, $storeId, $registerId);

        $row = [
            'id' => (int)$product->getId(),
            'sku' => (string)$product->getSku(),
            'name' => (string)$product->getName(),
            'price' => round($finalPrice, 4),
            'image' => $this->getImageUrl($product),
            'type' => $typeId,

            'price_from' => $isComposite,

            'has_options' => $isComposite || (bool)$product->getData('has_options'),
            'requires_options' => $isComposite || (bool)$product->getData('required_options'),
        ];

        if ($originalPrice !== null && $originalPrice > $finalPrice + 0.0001) {
            $row['original_price'] = round($originalPrice, 4);
        } else {
            $row['original_price'] = null;
        }

        if ($salableQty === null) {
            $row['in_stock'] = true;
            $row['salable_qty'] = null;
            $row['low_stock'] = false;
        } else {
            $row['in_stock'] = $salableQty > 0;
            $row['salable_qty'] = round($salableQty, 4);
            $row['low_stock'] = $salableQty > 0 && $salableQty <= self::LOW_STOCK_THRESHOLD;
        }

        return $row;
    }

    private function resolvePrices(Product $product, bool $preferMinPrice = false): array
    {
        $final = $product->getData('final_price');
        $regular = $product->getData('price');

        if ($preferMinPrice) {
            $minPrice = $product->getData('min_price');
            if ($minPrice !== null && $minPrice !== '' && (float)$minPrice > 0) {
                $final = $minPrice;
            }
        }
        if ($final === null || $final === '' || ($preferMinPrice && (float)$final <= 0)) {
            $final = $product->getData('min_price');
        }
        if ($final === null || $final === '') {
            $final = $product->getFinalPrice();
        }
        if ($regular === null || $regular === '') {
            $regular = $product->getPrice();
        }

        $finalF = (float)$final;
        $regularF = ($regular === null || $regular === '') ? null : (float)$regular;

        return [$finalF, $regularF];
    }

    private function resolveSalableQty(Product $product, int $storeId, ?int $registerId): ?float
    {
        if ($this->isMsiActive()) {
            $stockId = $this->resolveStockId($storeId, $registerId);
            if ($stockId !== null && $this->getProductSalableQty !== null) {
                $sku = (string)$product->getSku();
                $cacheKey = $stockId . ':' . $sku;
                if (array_key_exists($cacheKey, $this->salableQtyCache)) {
                    return $this->salableQtyCache[$cacheKey];
                }
                try {
                    $qty = (float)$this->getProductSalableQty->execute($sku, $stockId);
                } catch (\Exception $e) {
                    $qty = 0.0;
                }

                return $this->salableQtyCache[$cacheKey] = $qty;
            }
        }

        return $this->legacySalableQty($product);
    }

    private function legacySalableQty(Product $product): ?float
    {
        $extension = $product->getExtensionAttributes();
        if ($extension !== null && method_exists($extension, 'getStockItem')) {
            $stockItem = $extension->getStockItem();
            if ($stockItem !== null) {
                if (!(bool)$stockItem->getIsInStock()) {
                    return 0.0;
                }
                $qty = $stockItem->getQty();
                $minQty = $stockItem->getMinQty();
                if ($qty !== null) {
                    return max(0.0, (float)$qty - (float)$minQty);
                }
            }
        }

        $qty = $product->getData('qty');
        if ($qty !== null && $qty !== '') {
            return max(0.0, (float)$qty);
        }

        return null;
    }

    private function isMsiActive(): bool
    {
        if ($this->msiActive !== null) {
            return $this->msiActive;
        }

        $this->msiActive = $this->getProductSalableQty !== null
            && $this->defaultStockProvider !== null
            && $this->moduleManager->isEnabled('Magento_InventorySales');

        return $this->msiActive;
    }

    private function resolveStockId(int $storeId, ?int $registerId): ?int
    {
        $cacheKey = $registerId ?? -1;
        if (isset($this->stockIdCache[$cacheKey])) {
            return $this->stockIdCache[$cacheKey];
        }

        $stockId = $this->resolveStockIdFromSource($registerId);
        if ($stockId === null) {
            $stockId = $this->resolveWebsiteStockId($storeId);
        }
        if ($stockId === null && $this->defaultStockProvider !== null) {
            $stockId = (int)$this->defaultStockProvider->getId();
        }

        if ($stockId !== null) {
            $this->stockIdCache[$cacheKey] = $stockId;
        }

        return $stockId;
    }

    private function resolveStockIdFromSource(?int $registerId): ?int
    {
        if ($registerId === null
            || $registerId <= 0
            || $this->registerRepository === null
            || $this->getStockSourceLinks === null
            || $this->searchCriteriaBuilder === null
        ) {
            return null;
        }

        try {
            $register = $this->registerRepository->getById($registerId);
        } catch (\Exception $e) {
            return null;
        }

        $sourceCode = '';
        if (method_exists($register, 'getSourceCode')) {
            $sourceCode = (string)$register->getSourceCode();
        } elseif (method_exists($register, 'getData')) {
            $sourceCode = (string)$register->getData('source_code');
        }
        if ($sourceCode === '') {
            return null;
        }

        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter(StockSourceLinkInterface::SOURCE_CODE, $sourceCode)
                ->create();
            $links = $this->getStockSourceLinks->execute($criteria)->getItems();
        } catch (\Exception $e) {
            return null;
        }

        $best = null;
        $bestPriority = PHP_INT_MAX;
        foreach ($links as $link) {
            $priority = (int)$link->getPriority();
            if ($link->getStockId() !== null && $priority < $bestPriority) {
                $bestPriority = $priority;
                $best = (int)$link->getStockId();
            }
        }

        return $best;
    }

    private function resolveWebsiteStockId(int $storeId): ?int
    {
        if ($this->getStockBySalesChannel === null || $this->salesChannelFactory === null) {
            return null;
        }
        try {
            $websiteCode = (string)$this->storeManager->getStore($storeId)->getWebsite()->getCode();
            if ($websiteCode === '') {
                return null;
            }

            $salesChannel = $this->salesChannelFactory->create();
            $salesChannel->setType(SalesChannelInterface::TYPE_WEBSITE);
            $salesChannel->setCode($websiteCode);

            $stock = $this->getStockBySalesChannel->execute($salesChannel);
            $stockId = $stock->getStockId();
            return $stockId !== null ? (int)$stockId : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getImageUrl(Product $product): string
    {
        $cacheKey = (string)$product->getData('thumbnail')
            . '|' . (string)$product->getData('small_image')
            . '|' . (string)$product->getData('image');
        if (isset($this->imageUrlCache[$cacheKey])) {
            return $this->imageUrlCache[$cacheKey];
        }

        try {
            $url = $this->imageHelper
                ->init($product, self::IMAGE_ID)
                ->resize(self::IMAGE_SIZE)
                ->getUrl();
        } catch (\Exception $e) {
            if ($this->placeholderUrl === null) {
                $this->placeholderUrl = $this->imageHelper->getDefaultPlaceholderUrl('thumbnail');
            }
            $url = $this->placeholderUrl;
        }

        return $this->imageUrlCache[$cacheKey] = $url;
    }

    private function attributeExists(string $attributeCode): bool
    {
        if ($attributeCode === '' || !preg_match('/^[a-z0-9_]+$/i', $attributeCode)) {
            return false;
        }
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $attributeCode);
        } catch (\Exception $e) {
            return false;
        }

        return $attribute->getId() !== null;
    }

    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
