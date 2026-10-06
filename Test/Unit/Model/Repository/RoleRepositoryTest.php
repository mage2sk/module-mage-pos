<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/AbstractRepositoryTestCase.php';

use Magento\Framework\Api\SearchResultsInterfaceFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Panth\MagePos\Model\Role;
use Panth\MagePos\Model\RoleFactory;
use Panth\MagePos\Model\RoleRepository;
use Panth\MagePos\Model\ResourceModel\Role as RoleResource;
use Panth\MagePos\Model\ResourceModel\Role\Collection;
use Panth\MagePos\Model\ResourceModel\Role\CollectionFactory;

#[AllowMockObjectsWithoutExpectations]
class RoleRepositoryTest extends AbstractRepositoryTestCase
{
    protected function repositoryClass(): string
    {
        return RoleRepository::class;
    }

    protected function resourceClass(): string
    {
        return RoleResource::class;
    }

    protected function entityFactoryClass(): string
    {
        return RoleFactory::class;
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
        return Role::class;
    }

    protected function label(): string
    {
        return 'POS role';
    }

    protected function notFoundMessage(int $id): string
    {
        return 'POS role with id "' . $id . '" does not exist.';
    }

    protected function idGetter(): string
    {
        return 'getRoleId';
    }

    protected function processorBeforeSearchResults(): bool
    {
        return true;
    }
}
