<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Config\Source;

require_once __DIR__ . '/../../../autoload.php';

use Panth\MagePos\Api\Data\RoleInterface;
use Panth\MagePos\Model\Config\Source\Roles;
use Panth\MagePos\Model\ResourceModel\Role\Collection;
use Panth\MagePos\Model\ResourceModel\Role\CollectionFactory;
use Panth\MagePos\Model\Role;
use PHPUnit\Framework\TestCase;

class RolesTest extends TestCase
{
    private function makeRole(int $id, string $name): Role
    {
        $role = $this->createStub(Role::class);
        $role->method('getRoleId')->willReturn($id);
        $role->method('getName')->willReturn($name);

        return $role;
    }

    public function testListsNoRoleOptionFollowedByRolesSortedByName(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('setOrder')->with(RoleInterface::NAME, 'ASC');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->makeRole(2, 'Cashier'),
            $this->makeRole(1, 'Manager'),
        ]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($collection);

        $source = new Roles($factory);
        $options = $source->toOptionArray();

        $this->assertSame(
            [
                ['value' => '', 'label' => '-- No Role --'],
                ['value' => '2', 'label' => 'Cashier'],
                ['value' => '1', 'label' => 'Manager'],
            ],
            $options
        );
        $this->assertSame($options, $source->toOptionArray());
    }

    public function testWithoutRolesOnlyTheEmptyOptionIsReturned(): void
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $this->assertSame([['value' => '', 'label' => '-- No Role --']], (new Roles($factory))->toOptionArray());
    }
}
