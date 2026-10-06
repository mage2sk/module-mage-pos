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
use Panth\MagePos\Api\Data\PosOrderPaymentInterface;
use Panth\MagePos\Api\PosOrderPaymentRepositoryInterface;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment as PosOrderPaymentResource;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\CollectionFactory as PosOrderPaymentCollectionFactory;

class PosOrderPaymentRepository implements PosOrderPaymentRepositoryInterface
{
    public function __construct(
        private readonly PosOrderPaymentResource $resource,
        private readonly PosOrderPaymentFactory $posOrderPaymentFactory,
        private readonly PosOrderPaymentCollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(PosOrderPaymentInterface $posOrderPayment): PosOrderPaymentInterface
    {
        try {
            $this->resource->save($posOrderPayment);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('Could not save the POS order payment: %1', $e->getMessage()), $e);
        }
        return $posOrderPayment;
    }

    public function getById(int $id): PosOrderPaymentInterface
    {
        $posOrderPayment = $this->posOrderPaymentFactory->create();
        $this->resource->load($posOrderPayment, $id);
        if (!$posOrderPayment->getId()) {
            throw new NoSuchEntityException(__('POS order payment with ID "%1" does not exist.', $id));
        }
        return $posOrderPayment;
    }

    public function delete(PosOrderPaymentInterface $posOrderPayment): bool
    {
        try {
            $this->resource->delete($posOrderPayment);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(__('Could not delete the POS order payment: %1', $e->getMessage()), $e);
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
