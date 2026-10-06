<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsFactory;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Api\CashMovementRepositoryInterface;
use Panth\MagePos\Api\Data\CashMovementInterface;
use Panth\MagePos\Model\ResourceModel\CashMovement as CashMovementResource;
use Panth\MagePos\Model\ResourceModel\CashMovement\CollectionFactory as CashMovementCollectionFactory;

class CashMovementRepository implements CashMovementRepositoryInterface
{
    public function __construct(
        private readonly CashMovementResource $resource,
        private readonly CashMovementFactory $cashMovementFactory,
        private readonly CashMovementCollectionFactory $collectionFactory,
        private readonly SearchResultsFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(CashMovementInterface $cashMovement): CashMovementInterface
    {
        try {
            $this->resource->save($cashMovement);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(
                __('Could not save the POS cash movement: %1', $e->getMessage()),
                $e
            );
        }
        return $cashMovement;
    }

    public function getById(int $id): CashMovementInterface
    {
        $cashMovement = $this->cashMovementFactory->create();
        $this->resource->load($cashMovement, $id);
        if (!$cashMovement->getId()) {
            throw new NoSuchEntityException(__('POS cash movement with ID "%1" does not exist.', $id));
        }
        return $cashMovement;
    }

    public function delete(CashMovementInterface $cashMovement): bool
    {
        try {
            $this->resource->delete($cashMovement);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(
                __('Could not delete the POS cash movement: %1', $e->getMessage()),
                $e
            );
        }
        return true;
    }

    public function deleteById(int $id): bool
    {
        return $this->delete($this->getById($id));
    }

    public function getList(SearchCriteriaInterface $criteria): SearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($criteria, $collection);

        $results = $this->searchResultsFactory->create();
        $results->setSearchCriteria($criteria);
        $results->setItems($collection->getItems());
        $results->setTotalCount($collection->getSize());
        return $results;
    }
}
