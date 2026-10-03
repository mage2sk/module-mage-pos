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
use Panth\MagePos\Api\Data\RoleInterface;
use Panth\MagePos\Api\RoleRepositoryInterface;
use Panth\MagePos\Model\ResourceModel\Role as RoleResource;
use Panth\MagePos\Model\ResourceModel\Role\Collection;
use Panth\MagePos\Model\ResourceModel\Role\CollectionFactory;

class RoleRepository implements RoleRepositoryInterface
{
    public function __construct(
        private readonly RoleResource $resource,
        private readonly RoleFactory $roleFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory
    ) {
    }

    public function save(RoleInterface $role): RoleInterface
    {
        try {
            $this->resource->save($role);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('Could not save the POS role: %1', $e->getMessage()),
                $e
            );
        }

        return $role;
    }

    public function getById(int $id): RoleInterface
    {
        $role = $this->roleFactory->create();
        $this->resource->load($role, $id);
        if (!$role->getRoleId()) {
            throw new NoSuchEntityException(
                __('POS role with id "%1" does not exist.', $id)
            );
        }

        return $role;
    }

    public function delete(RoleInterface $role): bool
    {
        try {
            $this->resource->delete($role);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(
                __('Could not delete the POS role: %1', $e->getMessage()),
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
