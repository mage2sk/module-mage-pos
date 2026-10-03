<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;

trait PosTestHelperTrait
{
    protected array $criteriaFilters = [];

    protected function makeModel(string $class, string $idField, array $data = []): object
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn($idField);

        return new $class(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $resource,
            null,
            $data
        );
    }

    protected function makeCriteriaBuilder(): SearchCriteriaBuilder
    {
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(
            function ($field, $value, $condition = 'eq') use (&$builder) {
                $this->criteriaFilters[] = [$field, $value, $condition];
                return $builder;
            }
        );
        $builder->method('setSortOrders')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('setCurrentPage')->willReturnSelf();
        $builder->method('addSortOrder')->willReturnSelf();
        $builder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        return $builder;
    }

    protected function makeSortOrderBuilder(): SortOrderBuilder
    {
        $builder = $this->createStub(SortOrderBuilder::class);
        $builder->method('setField')->willReturnSelf();
        $builder->method('setDirection')->willReturnSelf();
        $builder->method('setAscendingDirection')->willReturnSelf();
        $builder->method('setDescendingDirection')->willReturnSelf();
        $builder->method('create')->willReturn($this->createStub(SortOrder::class));

        return $builder;
    }

    protected function makeSearchResults(array $items): SearchResultsInterface
    {
        $results = $this->createStub(SearchResultsInterface::class);
        $results->method('getItems')->willReturn($items);
        $results->method('getTotalCount')->willReturn(count($items));

        return $results;
    }

    protected function installObjectManager(): void
    {
        $objectManager = $this->createStub(\Magento\Framework\ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn (string $class) => $this->createStub($class));
        \Magento\Framework\App\ObjectManager::setInstance($objectManager);
    }

    protected function resetObjectManager(): void
    {
        $property = new \ReflectionProperty(\Magento\Framework\App\ObjectManager::class, '_instance');
        $property->setValue(null, null);
    }
}
