<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/AbstractRepositoryTestCase.php';

use Magento\Framework\Api\SearchResultsInterfaceFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Model\RegisterFactory;
use Panth\MagePos\Model\RegisterRepository;
use Panth\MagePos\Model\ResourceModel\Register as RegisterResource;
use Panth\MagePos\Model\ResourceModel\Register\Collection;
use Panth\MagePos\Model\ResourceModel\Register\CollectionFactory;

#[AllowMockObjectsWithoutExpectations]
class RegisterRepositoryTest extends AbstractRepositoryTestCase
{
    protected function repositoryClass(): string
    {
        return RegisterRepository::class;
    }

    protected function resourceClass(): string
    {
        return RegisterResource::class;
    }

    protected function entityFactoryClass(): string
    {
        return RegisterFactory::class;
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
        return Register::class;
    }

    protected function label(): string
    {
        return 'POS register';
    }

    protected function notFoundMessage(int $id): string
    {
        return 'POS register with ID "' . $id . '" does not exist.';
    }
}
