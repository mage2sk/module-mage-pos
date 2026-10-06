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
use Panth\MagePos\Api\Data\PosOrderInterface;
use Panth\MagePos\Api\PosOrderRepositoryInterface;
use Panth\MagePos\Model\ResourceModel\PosOrder as PosOrderResource;
use Panth\MagePos\Model\ResourceModel\PosOrder\CollectionFactory as PosOrderCollectionFactory;

class PosOrderRepository implements PosOrderRepositoryInterface
{
    public function __construct(
        private readonly PosOrderResource $resource,
        private readonly PosOrderFactory $posOrderFactory,
        private readonly PosOrderCollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(PosOrderInterface $posOrder): PosOrderInterface
    {
        try {
            $this->resource->save($posOrder);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('Could not save the POS order: %1', $e->getMessage()), $e);
        }
        return $posOrder;
    }

    public function getById(int $id): PosOrderInterface
    {
        $posOrder = $this->posOrderFactory->create();
        $this->resource->load($posOrder, $id);
        if (!$posOrder->getId()) {
            throw new NoSuchEntityException(__('POS order with ID "%1" does not exist.', $id));
        }
        return $posOrder;
    }

    public function delete(PosOrderInterface $posOrder): bool
    {
        try {
            $this->resource->delete($posOrder);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(__('Could not delete the POS order: %1', $e->getMessage()), $e);
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
