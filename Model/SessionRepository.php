<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsFactory;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Model\ResourceModel\Session as SessionResource;
use Panth\MagePos\Model\ResourceModel\Session\CollectionFactory as SessionCollectionFactory;

class SessionRepository implements SessionRepositoryInterface
{
    public function __construct(
        private readonly SessionResource $resource,
        private readonly SessionFactory $sessionFactory,
        private readonly SessionCollectionFactory $collectionFactory,
        private readonly SearchResultsFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(SessionInterface $session): SessionInterface
    {
        try {
            $this->resource->save($session);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(
                __('Could not save the POS session: %1', $e->getMessage()),
                $e
            );
        }
        return $session;
    }

    public function getById(int $id): SessionInterface
    {
        $session = $this->sessionFactory->create();
        $this->resource->load($session, $id);
        if (!$session->getId()) {
            throw new NoSuchEntityException(__('POS session with ID "%1" does not exist.', $id));
        }
        return $session;
    }

    public function delete(SessionInterface $session): bool
    {
        try {
            $this->resource->delete($session);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(
                __('Could not delete the POS session: %1', $e->getMessage()),
                $e
            );
        }
        return true;
    }

    public function deleteById(int $id): bool
    {
        return $this->delete($this->getById($id));
    }

    public function getList(SearchCriteriaInterface $criteria): SearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($criteria, $collection);

        $results = $this->searchResultsFactory->create();
        $results->setSearchCriteria($criteria);
        $results->setItems($collection->getItems());
        $results->setTotalCount($collection->getSize());
        return $results;
    }
}
