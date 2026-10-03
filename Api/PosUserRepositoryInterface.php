<?php
declare(strict_types=1);

namespace Panth\MagePos\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Panth\MagePos\Api\Data\PosUserInterface;

/**
 * CRUD repository for POS users (cashiers) (`panth_pos_user` table).
 */
interface PosUserRepositoryInterface
{
    /**
     * Save an entity.
     *
     * @param \Panth\MagePos\Api\Data\PosUserInterface $posUser
     * @return \Panth\MagePos\Api\Data\PosUserInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(PosUserInterface $posUser): PosUserInterface;

    /**
     * Load an entity by its id.
     *
     * @param int $id
     * @return \Panth\MagePos\Api\Data\PosUserInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $id): PosUserInterface;

    /**
     * Delete an entity.
     *
     * @param \Panth\MagePos\Api\Data\PosUserInterface $posUser
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(PosUserInterface $posUser): bool;

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
