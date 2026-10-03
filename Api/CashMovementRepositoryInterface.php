<?php
declare(strict_types=1);

namespace Panth\MagePos\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Panth\MagePos\Api\Data\CashMovementInterface;

/**
 * CRUD repository for cash drawer movements (`panth_pos_cash_movement` table).
 */
interface CashMovementRepositoryInterface
{
    /**
     * Save an entity.
     *
     * @param \Panth\MagePos\Api\Data\CashMovementInterface $cashMovement
     * @return \Panth\MagePos\Api\Data\CashMovementInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(CashMovementInterface $cashMovement): CashMovementInterface;

    /**
     * Load an entity by its id.
     *
     * @param int $id
     * @return \Panth\MagePos\Api\Data\CashMovementInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $id): CashMovementInterface;

    /**
     * Delete an entity.
     *
     * @param \Panth\MagePos\Api\Data\CashMovementInterface $cashMovement
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(CashMovementInterface $cashMovement): bool;

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
