<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/AbstractRepositoryTestCase.php';

use Magento\Framework\Api\SearchResultsFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Panth\MagePos\Model\CashMovement;
use Panth\MagePos\Model\CashMovementFactory;
use Panth\MagePos\Model\CashMovementRepository;
use Panth\MagePos\Model\ResourceModel\CashMovement as CashMovementResource;
use Panth\MagePos\Model\ResourceModel\CashMovement\Collection;
use Panth\MagePos\Model\ResourceModel\CashMovement\CollectionFactory;

#[AllowMockObjectsWithoutExpectations]
class CashMovementRepositoryTest extends AbstractRepositoryTestCase
{
    protected function repositoryClass(): string
    {
        return CashMovementRepository::class;
    }

    protected function resourceClass(): string
    {
        return CashMovementResource::class;
    }

    protected function entityFactoryClass(): string
    {
        return CashMovementFactory::class;
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
        return CashMovement::class;
    }

    protected function label(): string
    {
        return 'POS cash movement';
    }

    protected function notFoundMessage(int $id): string
    {
        return 'POS cash movement with ID "' . $id . '" does not exist.';
    }
}
