<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Plugin;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;
use Panth\MagePos\Plugin\SalesOrderGridCollectionPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SalesOrderGridCollectionPluginTest extends TestCase
{
    private function makeResource(): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t) => 'pfx_' . $t);

        return $resource;
    }

    public function testOtherDataSourcesAreReturnedUntouched(): void
    {
        $collection = $this->createMock(AbstractDb::class);
        $collection->expects($this->never())->method('getSelect');
        $plugin = new SalesOrderGridCollectionPlugin($this->makeResource(), $this->createStub(LoggerInterface::class));

        $this->assertSame(
            $collection,
            $plugin->afterGetReport($this->createStub(CollectionFactory::class), $collection, 'customer_listing_data_source')
        );
    }

    public function testNonDbResultIsReturnedUntouched(): void
    {
        $result = new \stdClass();
        $plugin = new SalesOrderGridCollectionPlugin($this->makeResource(), $this->createStub(LoggerInterface::class));

        $this->assertSame(
            $result,
            $plugin->afterGetReport($this->createStub(CollectionFactory::class), $result, 'sales_order_grid_data_source')
        );
    }

    public function testSkipsJoinWhenAnyPosTableIsMissing(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(static fn (string $t) => $t !== 'pfx_panth_pos_user');
        $collection = $this->createMock(AbstractDb::class);
        $collection->method('getConnection')->willReturn($connection);
        $collection->expects($this->never())->method('getSelect');
        $collection->expects($this->never())->method('addFilterToMap');
        $plugin = new SalesOrderGridCollectionPlugin($this->makeResource(), $this->createStub(LoggerInterface::class));

        $this->assertSame(
            $collection,
            $plugin->afterGetReport($this->createStub(CollectionFactory::class), $collection, 'sales_order_grid_data_source')
        );
    }

    public function testJoinsRegisterAndCashierColumnsAndMapsFilters(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $joins = [];
        $select = $this->createMock(Select::class);
        $select->expects($this->exactly(3))->method('joinLeft')->willReturnCallback(
            function ($name, $cond, $cols) use (&$joins, $select) {
                $joins[] = [$name, $cond, $cols];
                return $select;
            }
        );
        $maps = [];
        $collection = $this->createMock(AbstractDb::class);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getSelect')->willReturn($select);
        $collection->expects($this->exactly(2))->method('addFilterToMap')->willReturnCallback(
            function ($filter, $alias) use (&$maps, $collection) {
                $maps[$filter] = $alias;
                return $collection;
            }
        );
        $plugin = new SalesOrderGridCollectionPlugin($this->makeResource(), $this->createStub(LoggerInterface::class));

        $plugin->afterGetReport($this->createStub(CollectionFactory::class), $collection, 'sales_order_grid_data_source');

        $this->assertSame(['ppo' => 'pfx_panth_pos_order'], $joins[0][0]);
        $this->assertSame('ppo.order_id = main_table.entity_id', $joins[0][1]);
        $this->assertSame(['pos_register' => 'ppr.name'], $joins[1][2]);
        $this->assertSame(['pos_cashier' => 'ppu.name'], $joins[2][2]);
        $this->assertSame(['pos_register' => 'ppr.name', 'pos_cashier' => 'ppu.name'], $maps);
    }

    public function testFailuresAreLoggedAndCollectionStillReturned(): void
    {
        $collection = $this->createStub(AbstractDb::class);
        $collection->method('getConnection')->willThrowException(new \RuntimeException('no connection'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('no connection'));
        $plugin = new SalesOrderGridCollectionPlugin($this->makeResource(), $logger);

        $this->assertSame(
            $collection,
            $plugin->afterGetReport($this->createStub(CollectionFactory::class), $collection, 'sales_order_grid_data_source')
        );
    }
}
