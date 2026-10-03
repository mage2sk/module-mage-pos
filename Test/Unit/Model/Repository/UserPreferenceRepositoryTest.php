<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/AbstractRepositoryTestCase.php';

use Magento\Framework\Api\SearchResultsInterfaceFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Panth\MagePos\Model\UserPreference;
use Panth\MagePos\Model\UserPreferenceFactory;
use Panth\MagePos\Model\UserPreferenceRepository;
use Panth\MagePos\Model\ResourceModel\UserPreference as UserPreferenceResource;
use Panth\MagePos\Model\ResourceModel\UserPreference\Collection;
use Panth\MagePos\Model\ResourceModel\UserPreference\CollectionFactory;

#[AllowMockObjectsWithoutExpectations]
class UserPreferenceRepositoryTest extends AbstractRepositoryTestCase
{
    protected function repositoryClass(): string
    {
        return UserPreferenceRepository::class;
    }

    protected function resourceClass(): string
    {
        return UserPreferenceResource::class;
    }

    protected function entityFactoryClass(): string
    {
        return UserPreferenceFactory::class;
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
        return UserPreference::class;
    }

    protected function label(): string
    {
        return 'POS user preference';
    }

    protected function notFoundMessage(int $id): string
    {
        return 'POS user preference with ID "' . $id . '" does not exist.';
    }
}
