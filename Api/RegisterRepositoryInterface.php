<?php
declare(strict_types=1);

namespace Panth\MagePos\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Panth\MagePos\Api\Data\RegisterInterface;

/**
 * CRUD repository for POS registers (`panth_pos_register` table).
 */
interface RegisterRepositoryInterface
{
    /**
     * Save an entity.
     *
     * @param \Panth\MagePos\Api\Data\RegisterInterface $register
     * @return \Panth\MagePos\Api\Data\RegisterInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(RegisterInterface $register): RegisterInterface;

    /**
     * Load an entity by its id.
     *
     * @param int $id
     * @return \Panth\MagePos\Api\Data\RegisterInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $id): RegisterInterface;

    /**
     * Delete an entity.
     *
     * @param \Panth\MagePos\Api\Data\RegisterInterface $register
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(RegisterInterface $register): bool;

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
