<?php
declare(strict_types=1);

namespace Panth\MagePos\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Panth\MagePos\Api\Data\UserPreferenceInterface;

/**
 * CRUD repository for POS user preferences (`panth_pos_user_preference` table).
 */
interface UserPreferenceRepositoryInterface
{
    /**
     * Save an entity.
     *
     * @param \Panth\MagePos\Api\Data\UserPreferenceInterface $userPreference
     * @return \Panth\MagePos\Api\Data\UserPreferenceInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(UserPreferenceInterface $userPreference): UserPreferenceInterface;

    /**
     * Load an entity by its id.
     *
     * @param int $id
     * @return \Panth\MagePos\Api\Data\UserPreferenceInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $id): UserPreferenceInterface;

    /**
     * Delete an entity.
     *
     * @param \Panth\MagePos\Api\Data\UserPreferenceInterface $userPreference
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(UserPreferenceInterface $userPreference): bool;

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
