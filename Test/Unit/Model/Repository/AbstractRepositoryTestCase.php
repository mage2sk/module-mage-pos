<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Repository;

require_once __DIR__ . '/../../autoload.php';

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
abstract class AbstractRepositoryTestCase extends TestCase
{
    protected MockObject $resource;
    protected MockObject $entityFactory;
    protected MockObject $collectionFactory;
    protected MockObject $collectionProcessor;
    protected MockObject $searchResultsFactory;
    protected object $repository;

    abstract protected function repositoryClass(): string;

    abstract protected function resourceClass(): string;

    abstract protected function entityFactoryClass(): string;

    abstract protected function collectionFactoryClass(): string;

    abstract protected function collectionClass(): string;

    abstract protected function searchResultsFactoryClass(): string;

    abstract protected function modelClass(): string;

    abstract protected function label(): string;

    abstract protected function notFoundMessage(int $id): string;

    protected function processorBeforeSearchResults(): bool
    {
        return false;
    }

    protected function idGetter(): string
    {
        return 'getId';
    }

    protected function setUp(): void
    {
        $this->resource = $this->createMock($this->resourceClass());
        $this->entityFactory = $this->createMock($this->entityFactoryClass());
        $this->collectionFactory = $this->createMock($this->collectionFactoryClass());
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);
        $this->searchResultsFactory = $this->createMock($this->searchResultsFactoryClass());

        $class = $this->repositoryClass();
        $this->repository = $this->processorBeforeSearchResults()
            ? new $class(
                $this->resource,
                $this->entityFactory,
                $this->collectionFactory,
                $this->collectionProcessor,
                $this->searchResultsFactory
            )
            : new $class(
                $this->resource,
                $this->entityFactory,
                $this->collectionFactory,
                $this->searchResultsFactory,
                $this->collectionProcessor
            );
    }

    private function makeEntity(?int $id): MockObject
    {
        $entity = $this->createMock($this->modelClass());
        $entity->method($this->idGetter())->willReturn($id);
        if ($this->idGetter() !== 'getId') {
            $entity->method('getId')->willReturn($id);
        }

        return $entity;
    }

    public function testSavePersistsAndReturnsTheSameEntity(): void
    {
        $entity = $this->makeEntity(3);
        $this->resource->expects($this->once())->method('save')->with($entity);

        $this->assertSame($entity, $this->repository->save($entity));
    }

    public function testSaveWrapsResourceFailureInCouldNotSaveException(): void
    {
        $entity = $this->makeEntity(3);
        $this->resource->expects($this->once())->method('save')
            ->willThrowException(new \RuntimeException('db down'));

        try {
            $this->repository->save($entity);
            $this->fail('Expected CouldNotSaveException');
        } catch (CouldNotSaveException $e) {
            $this->assertSame('Could not save the ' . $this->label() . ': db down', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testGetByIdLoadsEntityThroughResource(): void
    {
        $entity = $this->makeEntity(42);
        $this->entityFactory->expects($this->once())->method('create')->willReturn($entity);
        $this->resource->expects($this->once())->method('load')->with($entity, 42);

        $this->assertSame($entity, $this->repository->getById(42));
    }

    public function testGetByIdThrowsWhenEntityDoesNotExist(): void
    {
        $this->entityFactory->expects($this->once())->method('create')->willReturn($this->makeEntity(null));
        $this->resource->expects($this->once())->method('load');

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage($this->notFoundMessage(99));
        $this->repository->getById(99);
    }

    public function testDeleteReturnsTrueOnSuccess(): void
    {
        $entity = $this->makeEntity(3);
        $this->resource->expects($this->once())->method('delete')->with($entity);

        $this->assertTrue($this->repository->delete($entity));
    }

    public function testDeleteWrapsResourceFailureInCouldNotDeleteException(): void
    {
        $entity = $this->makeEntity(3);
        $this->resource->expects($this->once())->method('delete')
            ->willThrowException(new \RuntimeException('locked'));

        $this->expectException(CouldNotDeleteException::class);
        $this->expectExceptionMessage('Could not delete the ' . $this->label() . ': locked');
        $this->repository->delete($entity);
    }

    public function testDeleteByIdLoadsThenDeletes(): void
    {
        $entity = $this->makeEntity(5);
        $this->entityFactory->expects($this->once())->method('create')->willReturn($entity);
        $this->resource->expects($this->once())->method('load')->with($entity, 5);
        $this->resource->expects($this->once())->method('delete')->with($entity);

        $this->assertTrue($this->repository->deleteById(5));
    }

    public function testDeleteByIdOfMissingEntityNeverCallsDelete(): void
    {
        $this->entityFactory->expects($this->once())->method('create')->willReturn($this->makeEntity(null));
        $this->resource->expects($this->never())->method('delete');

        $this->expectException(NoSuchEntityException::class);
        $this->repository->deleteById(5);
    }

    public function testGetListAppliesCriteriaAndFillsSearchResults(): void
    {
        $criteria = $this->createStub(SearchCriteriaInterface::class);
        $items = [$this->makeEntity(1), $this->makeEntity(2)];

        $collection = $this->createMock($this->collectionClass());
        $collection->method('getItems')->willReturn($items);
        $collection->method('getSize')->willReturn(17);
        $this->collectionFactory->expects($this->once())->method('create')->willReturn($collection);
        $this->collectionProcessor->expects($this->once())->method('process')->with($criteria, $collection);

        $results = $this->createMock(SearchResultsInterface::class);
        $results->expects($this->once())->method('setSearchCriteria')->with($criteria);
        $results->expects($this->once())->method('setItems')->with($items);
        $results->expects($this->once())->method('setTotalCount')->with(17);
        $this->searchResultsFactory->expects($this->once())->method('create')->willReturn($results);

        $this->assertSame($results, $this->repository->getList($criteria));
    }
}
