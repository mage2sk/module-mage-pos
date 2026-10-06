<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/AbstractRepositoryTestCase.php';

use Magento\Framework\Api\SearchResultsInterfaceFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Panth\MagePos\Model\PosUser;
use Panth\MagePos\Model\PosUserFactory;
use Panth\MagePos\Model\PosUserRepository;
use Panth\MagePos\Model\ResourceModel\PosUser as PosUserResource;
use Panth\MagePos\Model\ResourceModel\PosUser\Collection;
use Panth\MagePos\Model\ResourceModel\PosUser\CollectionFactory;

#[AllowMockObjectsWithoutExpectations]
class PosUserRepositoryTest extends AbstractRepositoryTestCase
{
    protected function repositoryClass(): string
    {
        return PosUserRepository::class;
    }

    protected function resourceClass(): string
    {
        return PosUserResource::class;
    }

    protected function entityFactoryClass(): string
    {
        return PosUserFactory::class;
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
        return SearchResultsInterfaceFactory::class;
    }

    protected function modelClass(): string
    {
        return PosUser::class;
    }

    protected function label(): string
    {
        return 'POS user';
    }

    protected function notFoundMessage(int $id): string
    {
        return 'POS user with id "' . $id . '" does not exist.';
    }

    protected function idGetter(): string
    {
        return 'getUserId';
    }

    protected function processorBeforeSearchResults(): bool
    {
        return true;
    }
}
