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
use Panth\MagePos\Api\Data\PaymentMethodInterface;
use Panth\MagePos\Api\PaymentMethodRepositoryInterface;
use Panth\MagePos\Model\ResourceModel\PaymentMethod as PaymentMethodResource;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\CollectionFactory as PaymentMethodCollectionFactory;

class PaymentMethodRepository implements PaymentMethodRepositoryInterface
{
    public function __construct(
        private readonly PaymentMethodResource $resource,
        private readonly PaymentMethodFactory $paymentMethodFactory,
        private readonly PaymentMethodCollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(PaymentMethodInterface $paymentMethod): PaymentMethodInterface
    {
        try {
            $this->resource->save($paymentMethod);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('Could not save the POS payment method: %1', $e->getMessage()), $e);
        }
        return $paymentMethod;
    }

    public function getById(int $id): PaymentMethodInterface
    {
        $paymentMethod = $this->paymentMethodFactory->create();
        $this->resource->load($paymentMethod, $id);
        if (!$paymentMethod->getId()) {
            throw new NoSuchEntityException(__('POS payment method with ID "%1" does not exist.', $id));
        }
        return $paymentMethod;
    }

    public function delete(PaymentMethodInterface $paymentMethod): bool
    {
        try {
            $this->resource->delete($paymentMethod);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(__('Could not delete the POS payment method: %1', $e->getMessage()), $e);
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
