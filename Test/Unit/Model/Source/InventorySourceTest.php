<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Source;

require_once __DIR__ . '/../../autoload.php';

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager as ModuleManager;
use Panth\MagePos\Model\Source\InventorySource;
use PHPUnit\Framework\TestCase;

class InventorySourceTest extends TestCase
{
    private const DEFAULT_OPTION = ['value' => '', 'label' => '-- Magento Default Stock --'];

    public function testWithoutMsiOnlyDefaultStockIsOffered(): void
    {
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->expects($this->once())->method('isEnabled')->with('Magento_InventoryApi')->willReturn(false);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $this->assertSame([self::DEFAULT_OPTION], (new InventorySource($resource, $moduleManager))->toOptionArray());
    }

    public function testMissingSourceTableFallsBackToDefaultStock(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->with('inventory_source')->willReturn(false);
        $connection->expects($this->never())->method('fetchAll');

        $source = new InventorySource($this->makeResource($connection), $this->makeModuleManager(true));

        $this->assertSame([self::DEFAULT_OPTION], $source->toOptionArray());
    }

    public function testSourcesAreLabelledWithNameCodeAndDisabledMarker(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('select')->willReturn($select);
        $connection->expects($this->once())->method('fetchAll')->with($select)->willReturn([
            ['source_code' => 'default', 'name' => 'Default Source', 'enabled' => '1'],
            ['source_code' => 'wh2', 'name' => '  ', 'enabled' => '1'],
            ['source_code' => 'old', 'name' => 'Old Shop', 'enabled' => '0'],
            ['source_code' => '', 'name' => 'Broken', 'enabled' => '1'],
        ]);

        $source = new InventorySource($this->makeResource($connection), $this->makeModuleManager(true));
        $options = $source->toOptionArray();

        $this->assertSame(
            [
                self::DEFAULT_OPTION,
                ['value' => 'default', 'label' => 'Default Source [default]'],
                ['value' => 'wh2', 'label' => 'wh2 [wh2]'],
                ['value' => 'old', 'label' => 'Old Shop (disabled) [old]'],
            ],
            $options
        );
        $this->assertSame($options, $source->toOptionArray());
    }

    private function makeResource(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }

    private function makeModuleManager(bool $enabled): ModuleManager
    {
        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturn($enabled);

        return $moduleManager;
    }
}
