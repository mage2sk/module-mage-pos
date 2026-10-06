<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/AbstractRepositoryTestCase.php';

use Magento\Framework\Api\SearchResultsInterfaceFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Panth\MagePos\Model\PosOrderPayment;
use Panth\MagePos\Model\PosOrderPaymentFactory;
use Panth\MagePos\Model\PosOrderPaymentRepository;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment as PosOrderPaymentResource;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\Collection;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\CollectionFactory;

#[AllowMockObjectsWithoutExpectations]
class PosOrderPaymentRepositoryTest extends AbstractRepositoryTestCase
{
    protected function repositoryClass(): string
    {
        return PosOrderPaymentRepository::class;
    }

    protected function resourceClass(): string
    {
        return PosOrderPaymentResource::class;
    }

    protected function entityFactoryClass(): string
    {
        return PosOrderPaymentFactory::class;
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
        return PosOrderPayment::class;
    }

    protected function label(): string
    {
        return 'POS order payment';
    }

    protected function notFoundMessage(int $id): string
    {
        return 'POS order payment with ID "' . $id . '" does not exist.';
    }
}
