<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\ResourceModel;

require_once __DIR__ . '/../../autoload.php';

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\MagePos\Model\ResourceModel\PaymentMethod;
use Panth\MagePos\Model\ResourceModel\PosOrder;
use Panth\MagePos\Model\ResourceModel\QuickKey\Collection as QuickKeyCollection;
use Panth\MagePos\Model\ResourceModel\Hold\Collection as HoldCollection;
use Panth\MagePos\Model\ResourceModel\Register;
use Panth\MagePos\Model\ResourceModel\UserPreference;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class IdLookupTest extends TestCase
{
    private array $calls = [];

    private function makeResource(string $class, string $table, mixed $fetched): object
    {
        $this->calls = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnCallback(function ($t, $cols) use ($select) {
            $this->calls['from'] = [$t, $cols];
            return $select;
        });
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->calls['where'] = [$cond, $value];
            return $select;
        });
        $select->method('limit')->willReturnCallback(function ($n) use ($select) {
            $this->calls['limit'] = $n;
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($fetched);

        $resource = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection', 'getMainTable'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn($table);

        return $resource;
    }

    public static function lookupProvider(): array
    {
        return [
            'method by code' => [PaymentMethod::class, 'getIdByCode', 'cash', 'panth_pos_payment_method', 'method_id', 'code = ?'],
            'register by code' => [Register::class, 'getIdByCode', 'R1', 'panth_pos_register', 'register_id', 'code = ?'],
            'order by uuid' => [PosOrder::class, 'getIdByClientUuid', 'abc-1', 'panth_pos_order', 'pos_order_id', 'client_uuid = ?'],
            'order by order id' => [PosOrder::class, 'getIdByOrderId', 55, 'panth_pos_order', 'pos_order_id', 'order_id = ?'],
            'preference by user' => [UserPreference::class, 'getIdByUserId', 9, 'panth_pos_user_preference', 'preference_id', 'user_id = ?'],
        ];
    }

    #[DataProvider('lookupProvider')]
    public function testLookupQueriesSingleIdAndCastsResult(
        string $class,
        string $method,
        mixed $arg,
        string $table,
        string $column,
        string $condition
    ): void {
        $resource = $this->makeResource($class, $table, '17');

        $this->assertSame(17, $resource->$method($arg));
        $this->assertSame([$table, $column], $this->calls['from']);
        $this->assertSame([$condition, $arg], $this->calls['where']);
        $this->assertSame(1, $this->calls['limit']);
    }

    #[DataProvider('lookupProvider')]
    public function testLookupReturnsNullWhenNothingFound(
        string $class,
        string $method,
        mixed $arg,
        string $table,
        string $column,
        string $condition
    ): void
    {
        $this->assertNull($this->makeResource($class, $table, false)->$method($arg));
    }

    public static function emptyInputProvider(): array
    {
        return [
            [PaymentMethod::class, 'getIdByCode', ''],
            [Register::class, 'getIdByCode', ''],
            [PosOrder::class, 'getIdByClientUuid', ''],
            [UserPreference::class, 'getIdByUserId', 0],
            [UserPreference::class, 'getIdByUserId', -4],
        ];
    }

    #[DataProvider('emptyInputProvider')]
    public function testEmptyInputShortCircuitsWithoutQuery(string $class, string $method, mixed $arg): void
    {
        $resource = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection', 'getMainTable'])
            ->getMock();
        $resource->expects($this->never())->method('getConnection');

        $this->assertNull($resource->$method($arg));
    }

    public function testQuickKeyRegisterFilterIncludesGlobalKeysAndSortsByPagePosition(): void
    {
        $collection = $this->getMockBuilder(QuickKeyCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'setOrder'])
            ->getMock();
        $collection->expects($this->once())->method('addFieldToFilter')
            ->with('register_id', [['eq' => 3], ['null' => true]])->willReturnSelf();
        $orders = [];
        $collection->method('setOrder')->willReturnCallback(function ($f, $d) use (&$orders, $collection) {
            $orders[] = [$f, $d];
            return $collection;
        });

        $this->assertSame($collection, $collection->addRegisterFilter(3));
        $this->assertSame([['page', 'ASC'], ['position', 'ASC']], $orders);
    }

    public function testQuickKeyRegisterFilterWithoutRegisterSelectsGlobalKeysOnly(): void
    {
        $collection = $this->getMockBuilder(QuickKeyCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'setOrder'])
            ->getMock();
        $collection->expects($this->once())->method('addFieldToFilter')
            ->with('register_id', ['null' => true])->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();

        $collection->addRegisterFilter(null);
    }

    public function testHoldRegisterFilter(): void
    {
        $collection = $this->getMockBuilder(HoldCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter'])
            ->getMock();
        $collection->expects($this->once())->method('addFieldToFilter')->with('register_id', 8)->willReturnSelf();

        $this->assertSame($collection, $collection->addRegisterFilter(8));
    }
}
