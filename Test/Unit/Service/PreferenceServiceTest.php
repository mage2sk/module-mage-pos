<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../PosTestHelperTrait.php';

use Magento\Framework\Exception\CouldNotSaveException;
use Panth\MagePos\Api\UserPreferenceRepositoryInterface;
use Panth\MagePos\Model\UserPreference;
use Panth\MagePos\Model\UserPreferenceFactory;
use Panth\MagePos\Service\PreferenceService;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PreferenceServiceTest extends TestCase
{
    use PosTestHelperTrait;

    private UserPreferenceRepositoryInterface&MockObject $repository;
    private UserPreferenceFactory&MockObject $factory;

    protected function setUp(): void
    {
        $this->criteriaFilters = [];
        $this->repository = $this->createMock(UserPreferenceRepositoryInterface::class);
        $this->factory = $this->createMock(UserPreferenceFactory::class);
    }

    private function makeService(array $existing): PreferenceService
    {
        $this->repository->method('getList')->willReturn($this->makeSearchResults($existing));

        return new PreferenceService($this->repository, $this->factory, $this->makeCriteriaBuilder());
    }

    private function makePreference(array $data = []): UserPreference
    {
        return $this->makeModel(UserPreference::class, 'preference_id', $data);
    }

    public function testGetWithoutStoredPreferenceReturnsNulls(): void
    {
        $this->assertSame(['layout' => null, 'theme' => null], $this->makeService([])->get(4));
        $this->assertSame([['user_id', 4, 'eq']], $this->criteriaFilters);
    }

    public function testGetDecodesStoredJsonAndIgnoresInvalidJson(): void
    {
        $service = $this->makeService([
            $this->makePreference(['layout_json' => '{"grid":3}', 'theme_json' => 'not-json']),
        ]);

        $this->assertSame(['layout' => ['grid' => 3], 'theme' => null], $service->get(4));
    }

    public function testGetTreatsEmptyStringAsNull(): void
    {
        $service = $this->makeService([$this->makePreference(['layout_json' => '', 'theme_json' => '{"dark":true}'])]);

        $this->assertSame(['layout' => null, 'theme' => ['dark' => true]], $service->get(4));
    }

    public function testSaveWithNothingToStoreIsANoOp(): void
    {
        $this->repository->expects($this->never())->method('getList');
        $this->repository->expects($this->never())->method('save');

        (new PreferenceService($this->repository, $this->factory, $this->makeCriteriaBuilder()))->save(4, null, null);
    }

    public function testSaveCreatesPreferenceForNewUser(): void
    {
        $created = $this->makePreference();
        $this->factory->expects($this->once())->method('create')->willReturn($created);
        $this->repository->expects($this->once())->method('save')->with($created);

        $this->makeService([])->save(4, ['url' => 'a/b', 'name' => "Caf\u{e9}"], null);

        $this->assertSame(4, $created->getUserId());
        $this->assertSame('{"url":"a/b","name":"Caf' . "\u{e9}" . '"}', $created->getLayoutJson());
        $this->assertNull($created->getThemeJson());
    }

    public function testSaveUpdatesOnlyProvidedSectionOfExistingPreference(): void
    {
        $existing = $this->makePreference(['user_id' => 4, 'layout_json' => '{"keep":1}']);
        $this->factory->expects($this->never())->method('create');
        $this->repository->expects($this->once())->method('save')->with($existing);

        $this->makeService([$existing])->save(4, null, ['accent' => '#fff']);

        $this->assertSame('{"keep":1}', $existing->getLayoutJson());
        $this->assertSame('{"accent":"#fff"}', $existing->getThemeJson());
    }

    public function testSaveRejectsUnencodablePayload(): void
    {
        $this->factory->method('create')->willReturn($this->makePreference());
        $this->repository->expects($this->never())->method('save');

        $this->expectException(CouldNotSaveException::class);
        $this->makeService([])->save(4, ['bad' => "\xB1\x31"], null);
    }
}
