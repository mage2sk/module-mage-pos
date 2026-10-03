<?php
declare(strict_types=1);

namespace Panth\MagePos\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Panth\MagePos\Api\Data\QuickKeyInterface;

/**
 * CRUD repository for quick keys (`panth_pos_quick_key` table).
 */
interface QuickKeyRepositoryInterface
{
    /**
     * Save an entity.
     *
     * @param \Panth\MagePos\Api\Data\QuickKeyInterface $quickKey
     * @return \Panth\MagePos\Api\Data\QuickKeyInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(QuickKeyInterface $quickKey): QuickKeyInterface;

    /**
     * Load an entity by its id.
     *
     * @param int $id
     * @return \Panth\MagePos\Api\Data\QuickKeyInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $id): QuickKeyInterface;

    /**
     * Delete an entity.
     *
     * @param \Panth\MagePos\Api\Data\QuickKeyInterface $quickKey
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(QuickKeyInterface $quickKey): bool;

    /**
     * Delete an entity by its id.
     *
     * @param int $id
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function deleteById(int $id): bool;

    /**
     * Retrieve a list of entities matching the criteria.
     *
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Magento\Framework\Api\SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;
}
