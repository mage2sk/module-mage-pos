<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/AbstractRepositoryTestCase.php';

use Magento\Framework\Api\SearchResultsInterfaceFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Panth\MagePos\Model\QuickKey;
use Panth\MagePos\Model\QuickKeyFactory;
use Panth\MagePos\Model\QuickKeyRepository;
use Panth\MagePos\Model\ResourceModel\QuickKey as QuickKeyResource;
use Panth\MagePos\Model\ResourceModel\QuickKey\Collection;
use Panth\MagePos\Model\ResourceModel\QuickKey\CollectionFactory;

#[AllowMockObjectsWithoutExpectations]
class QuickKeyRepositoryTest extends AbstractRepositoryTestCase
{
    protected function repositoryClass(): string
    {
        return QuickKeyRepository::class;
    }

    protected function resourceClass(): string
    {
        return QuickKeyResource::class;
    }

    protected function entityFactoryClass(): string
    {
        return QuickKeyFactory::class;
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
        return QuickKey::class;
    }

    protected function label(): string
    {
        return 'POS quick key';
    }

    protected function notFoundMessage(int $id): string
    {
        return 'POS quick key with ID "' . $id . '" does not exist.';
    }
}
