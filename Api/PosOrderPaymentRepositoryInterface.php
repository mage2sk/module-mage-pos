<?php
declare(strict_types=1);

namespace Panth\MagePos\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Panth\MagePos\Api\Data\PosOrderPaymentInterface;

/**
 * CRUD repository for POS order payment rows (`panth_pos_order_payment` table).
 */
interface PosOrderPaymentRepositoryInterface
{
    /**
     * Save an entity.
     *
     * @param \Panth\MagePos\Api\Data\PosOrderPaymentInterface $posOrderPayment
     * @return \Panth\MagePos\Api\Data\PosOrderPaymentInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(PosOrderPaymentInterface $posOrderPayment): PosOrderPaymentInterface;

    /**
     * Load an entity by its id.
     *
     * @param int $id
     * @return \Panth\MagePos\Api\Data\PosOrderPaymentInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $id): PosOrderPaymentInterface;

    /**
     * Delete an entity.
     *
     * @param \Panth\MagePos\Api\Data\PosOrderPaymentInterface $posOrderPayment
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(PosOrderPaymentInterface $posOrderPayment): bool;

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
