<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/AbstractRepositoryTestCase.php';

use Magento\Framework\Api\SearchResultsInterfaceFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Panth\MagePos\Model\PaymentMethod;
use Panth\MagePos\Model\PaymentMethodFactory;
use Panth\MagePos\Model\PaymentMethodRepository;
use Panth\MagePos\Model\ResourceModel\PaymentMethod as PaymentMethodResource;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\Collection;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\CollectionFactory;

#[AllowMockObjectsWithoutExpectations]
class PaymentMethodRepositoryTest extends AbstractRepositoryTestCase
{
    protected function repositoryClass(): string
    {
        return PaymentMethodRepository::class;
    }

    protected function resourceClass(): string
    {
        return PaymentMethodResource::class;
    }

    protected function entityFactoryClass(): string
    {
        return PaymentMethodFactory::class;
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
        return PaymentMethod::class;
    }

    protected function label(): string
    {
        return 'POS payment method';
    }

    protected function notFoundMessage(int $id): string
    {
        return 'POS payment method with ID "' . $id . '" does not exist.';
    }
}
