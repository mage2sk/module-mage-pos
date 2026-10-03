<?php
declare(strict_types=1);

namespace Panth\MagePos\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Panth\MagePos\Api\Data\PosOrderInterface;

/**
 * CRUD repository for POS order metadata records (`panth_pos_order` table).
 */
interface PosOrderRepositoryInterface
{
    /**
     * Save an entity.
     *
     * @param \Panth\MagePos\Api\Data\PosOrderInterface $posOrder
     * @return \Panth\MagePos\Api\Data\PosOrderInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(PosOrderInterface $posOrder): PosOrderInterface;

    /**
     * Load an entity by its id.
     *
     * @param int $id
     * @return \Panth\MagePos\Api\Data\PosOrderInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $id): PosOrderInterface;

    /**
     * Delete an entity.
     *
     * @param \Panth\MagePos\Api\Data\PosOrderInterface $posOrder
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(PosOrderInterface $posOrder): bool;

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
