<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/AbstractRepositoryTestCase.php';

use Magento\Framework\Api\SearchResultsInterfaceFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Panth\MagePos\Model\PosOrder;
use Panth\MagePos\Model\PosOrderFactory;
use Panth\MagePos\Model\PosOrderRepository;
use Panth\MagePos\Model\ResourceModel\PosOrder as PosOrderResource;
use Panth\MagePos\Model\ResourceModel\PosOrder\Collection;
use Panth\MagePos\Model\ResourceModel\PosOrder\CollectionFactory;

#[AllowMockObjectsWithoutExpectations]
class PosOrderRepositoryTest extends AbstractRepositoryTestCase
{
    protected function repositoryClass(): string
    {
        return PosOrderRepository::class;
    }

    protected function resourceClass(): string
    {
        return PosOrderResource::class;
    }

    protected function entityFactoryClass(): string
    {
        return PosOrderFactory::class;
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
        return PosOrder::class;
    }

    protected function label(): string
    {
        return 'POS order';
    }

    protected function notFoundMessage(int $id): string
    {
        return 'POS order with ID "' . $id . '" does not exist.';
    }
}
