<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/AbstractRepositoryTestCase.php';

use Magento\Framework\Api\SearchResultsInterfaceFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Panth\MagePos\Model\Hold;
use Panth\MagePos\Model\HoldFactory;
use Panth\MagePos\Model\HoldRepository;
use Panth\MagePos\Model\ResourceModel\Hold as HoldResource;
use Panth\MagePos\Model\ResourceModel\Hold\Collection;
use Panth\MagePos\Model\ResourceModel\Hold\CollectionFactory;

#[AllowMockObjectsWithoutExpectations]
class HoldRepositoryTest extends AbstractRepositoryTestCase
{
    protected function repositoryClass(): string
    {
        return HoldRepository::class;
    }

    protected function resourceClass(): string
    {
        return HoldResource::class;
    }

    protected function entityFactoryClass(): string
    {
        return HoldFactory::class;
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
        return Hold::class;
    }

    protected function label(): string
    {
        return 'POS hold';
    }

    protected function notFoundMessage(int $id): string
    {
        return 'POS hold with ID "' . $id . '" does not exist.';
    }
}
