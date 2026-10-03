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
use Panth\MagePos\Api\Data\UserPreferenceInterface;
use Panth\MagePos\Api\UserPreferenceRepositoryInterface;
use Panth\MagePos\Model\ResourceModel\UserPreference as UserPreferenceResource;
use Panth\MagePos\Model\ResourceModel\UserPreference\CollectionFactory as UserPreferenceCollectionFactory;

class UserPreferenceRepository implements UserPreferenceRepositoryInterface
{
    public function __construct(
        private readonly UserPreferenceResource $resource,
        private readonly UserPreferenceFactory $userPreferenceFactory,
        private readonly UserPreferenceCollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(UserPreferenceInterface $userPreference): UserPreferenceInterface
    {
        try {
            $this->resource->save($userPreference);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('Could not save the POS user preference: %1', $e->getMessage()), $e);
        }
        return $userPreference;
    }

    public function getById(int $id): UserPreferenceInterface
    {
        $userPreference = $this->userPreferenceFactory->create();
        $this->resource->load($userPreference, $id);
        if (!$userPreference->getId()) {
            throw new NoSuchEntityException(__('POS user preference with ID "%1" does not exist.', $id));
        }
        return $userPreference;
    }

    public function delete(UserPreferenceInterface $userPreference): bool
    {
        try {
            $this->resource->delete($userPreference);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(__('Could not delete the POS user preference: %1', $e->getMessage()), $e);
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
