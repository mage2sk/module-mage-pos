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
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Model\ResourceModel\PosUser as PosUserResource;
use Panth\MagePos\Model\ResourceModel\PosUser\Collection;
use Panth\MagePos\Model\ResourceModel\PosUser\CollectionFactory;

class PosUserRepository implements PosUserRepositoryInterface
{
    public function __construct(
        private readonly PosUserResource $resource,
        private readonly PosUserFactory $posUserFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory
    ) {
    }

    public function save(PosUserInterface $posUser): PosUserInterface
    {
        try {
            $this->resource->save($posUser);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('Could not save the POS user: %1', $e->getMessage()),
                $e
            );
        }

        return $posUser;
    }

    public function getById(int $id): PosUserInterface
    {
        $posUser = $this->posUserFactory->create();
        $this->resource->load($posUser, $id);
        if (!$posUser->getUserId()) {
            throw new NoSuchEntityException(
                __('POS user with id "%1" does not exist.', $id)
            );
        }

        return $posUser;
    }

    public function delete(PosUserInterface $posUser): bool
    {
        try {
            $this->resource->delete($posUser);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(
                __('Could not delete the POS user: %1', $e->getMessage()),
                $e
            );
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

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }
}
