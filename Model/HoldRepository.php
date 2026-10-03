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
use Panth\MagePos\Api\Data\HoldInterface;
use Panth\MagePos\Api\HoldRepositoryInterface;
use Panth\MagePos\Model\ResourceModel\Hold as HoldResource;
use Panth\MagePos\Model\ResourceModel\Hold\CollectionFactory as HoldCollectionFactory;

class HoldRepository implements HoldRepositoryInterface
{
    public function __construct(
        private readonly HoldResource $resource,
        private readonly HoldFactory $holdFactory,
        private readonly HoldCollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(HoldInterface $hold): HoldInterface
    {
        try {
            $this->resource->save($hold);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('Could not save the POS hold: %1', $e->getMessage()), $e);
        }
        return $hold;
    }

    public function getById(int $id): HoldInterface
    {
        $hold = $this->holdFactory->create();
        $this->resource->load($hold, $id);
        if (!$hold->getId()) {
            throw new NoSuchEntityException(__('POS hold with ID "%1" does not exist.', $id));
        }
        return $hold;
    }

    public function delete(HoldInterface $hold): bool
    {
        try {
            $this->resource->delete($hold);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(__('Could not delete the POS hold: %1', $e->getMessage()), $e);
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
