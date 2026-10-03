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
use Panth\MagePos\Api\Data\RegisterInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Model\ResourceModel\Register as RegisterResource;
use Panth\MagePos\Model\ResourceModel\Register\CollectionFactory as RegisterCollectionFactory;

class RegisterRepository implements RegisterRepositoryInterface
{
    public function __construct(
        private readonly RegisterResource $resource,
        private readonly RegisterFactory $registerFactory,
        private readonly RegisterCollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(RegisterInterface $register): RegisterInterface
    {
        try {
            $this->resource->save($register);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('Could not save the POS register: %1', $e->getMessage()), $e);
        }
        return $register;
    }

    public function getById(int $id): RegisterInterface
    {
        $register = $this->registerFactory->create();
        $this->resource->load($register, $id);
        if (!$register->getId()) {
            throw new NoSuchEntityException(__('POS register with ID "%1" does not exist.', $id));
        }
        return $register;
    }

    public function delete(RegisterInterface $register): bool
    {
        try {
            $this->resource->delete($register);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(__('Could not delete the POS register: %1', $e->getMessage()), $e);
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
