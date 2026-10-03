<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Api\Data\QuickKeyInterface;
use Panth\MagePos\Api\QuickKeyRepositoryInterface;
use Panth\MagePos\Model\ResourceModel\QuickKey as QuickKeyResource;
use Panth\MagePos\Model\ResourceModel\QuickKey\CollectionFactory as QuickKeyCollectionFactory;

class QuickKeyRepository implements QuickKeyRepositoryInterface
{
    public function __construct(
        private readonly QuickKeyResource $resource,
        private readonly QuickKeyFactory $quickKeyFactory,
        private readonly QuickKeyCollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(QuickKeyInterface $quickKey): QuickKeyInterface
    {
        try {
            $this->resource->save($quickKey);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('Could not save the POS quick key: %1', $e->getMessage()), $e);
        }
        return $quickKey;
    }

    public function getById(int $id): QuickKeyInterface
    {
        $quickKey = $this->quickKeyFactory->create();
        $this->resource->load($quickKey, $id);
        if (!$quickKey->getId()) {
            throw new NoSuchEntityException(__('POS quick key with ID "%1" does not exist.', $id));
        }
        return $quickKey;
    }

    public function delete(QuickKeyInterface $quickKey): bool
    {
        try {
            $this->resource->delete($quickKey);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(__('Could not delete the POS quick key: %1', $e->getMessage()), $e);
        }
        return true;
    }

    public function deleteById(int $id): bool
    {
        return $this->delete($this->getById($id));
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $results = $this->searchResultsFactory->create();
        $results->setSearchCriteria($searchCriteria);
        $results->setItems($collection->getItems());
        $results->setTotalCount($collection->getSize());
        return $results;
    }
}
