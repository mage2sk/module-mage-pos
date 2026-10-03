<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/AbstractRepositoryTestCase.php';

use Magento\Framework\Api\SearchResultsFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Panth\MagePos\Model\Session;
use Panth\MagePos\Model\SessionFactory;
use Panth\MagePos\Model\SessionRepository;
use Panth\MagePos\Model\ResourceModel\Session as SessionResource;
use Panth\MagePos\Model\ResourceModel\Session\Collection;
use Panth\MagePos\Model\ResourceModel\Session\CollectionFactory;

#[AllowMockObjectsWithoutExpectations]
class SessionRepositoryTest extends AbstractRepositoryTestCase
{
    protected function repositoryClass(): string
    {
        return SessionRepository::class;
    }

    protected function resourceClass(): string
    {
        return SessionResource::class;
    }

    protected function entityFactoryClass(): string
    {
        return SessionFactory::class;
    }

    protected function collectionFactoryClass(): string
    {
        return CollectionFactory::class;
    }

    protected function collectionClass(): string
    {
        return Collection::class;
    }

    protected function searchResultsFactoryClass(): string
    {
        return SearchResultsFactory::class;
    }

    protected function modelClass(): string
    {
        return Session::class;
    }

    protected function label(): string
    {
        return 'POS session';
    }

    protected function notFoundMessage(int $id): string
    {
        return 'POS session with ID "' . $id . '" does not exist.';
    }
}
