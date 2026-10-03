<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../PosTestHelperTrait.php';

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Model\PosUser;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Model\Session;
use Panth\MagePos\Service\ReportService;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ReportServiceTest extends TestCase
{
    use PosTestHelperTrait;

    private \SplObjectStorage $selects;
    private array $data = [];
    private SessionRepositoryInterface&MockObject $sessionRepository;
    private RegisterRepositoryInterface&MockObject $registerRepository;
    private PosUserRepositoryInterface&MockObject $userRepository;

    protected function setUp(): void
    {
        $this->selects = new \SplObjectStorage();
        $this->data = [
            'totals' => ['orders_count' => '0', 'gross' => '0'],
            'payments' => [],
            'cashiers' => [],
            'registers' => [],
            'hours' => [],
            'movements' => [],
        ];
        $this->sessionRepository = $this->createMock(SessionRepositoryInterface::class);
        $this->registerRepository = $this->createMock(RegisterRepositoryInterface::class);
        $this->userRepository = $this->createMock(PosUserRepositoryInterface::class);
    }

    private function newSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $record = (object) ['from' => null, 'joins' => [], 'where' => []];
        $this->selects[$select] = $record;
        $select->method('from')->willReturnCallback(function ($name) use ($select, $record) {
            $record->from = array_key_first((array) $name);
            return $select;
        });
        $join = function ($name) use ($select, $record) {
            $joins = $record->joins;
            $joins[] = array_key_first((array) $name);
            $record->joins = $joins;
            return $select;
        };
        $select->method('join')->willReturnCallback($join);
        $select->method('joinLeft')->willReturnCallback($join);
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select, $record) {
            $where = $record->where;
            $where[$cond] = $value;
            $record->where = $where;
            return $select;
        });
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        return $select;
    }

    private function kind(Select $select): string
    {
        $record = $this->selects[$select];
        return match (true) {
            $record->from === 'pp' => 'payments',
            $record->from === 'pcm' => 'movements',
            in_array('ppu', $record->joins, true) => 'cashiers',
            in_array('ppr', $record->joins, true) => 'registers',
            default => 'hours',
        };
    }

    private function makeService(string $timezone = 'UTC'): ReportService
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->newSelect());
        $connection->method('fetchRow')->willReturnCallback(fn () => $this->data['totals']);
        $connection->method('fetchAll')->willReturnCallback(fn (Select $s) => $this->data[$this->kind($s)]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $tz = $this->createStub(TimezoneInterface::class);
        $tz->method('getConfigTimezone')->willReturn($timezone);

        return new ReportService($resource, $this->sessionRepository, $this->registerRepository, $this->userRepository, $tz);
    }

    private function wheres(): array
    {
        $all = [];
        foreach ($this->selects as $select) {
            $all[] = $this->selects[$select]->where;
        }
        return $all;
    }

    public function testSalesSummaryConvertsRangeToUtcAndAggregates(): void
    {
        $this->data['totals'] = ['orders_count' => '3', 'gross' => '100.00005'];
        $this->data['payments'] = [['method_code' => 'cash', 'method_title' => 'Cash', 'amount' => '60.5', 'orders_count' => '2']];
        $this->data['cashiers'] = [
            ['user_id' => '4', 'name' => 'Ann', 'orders_count' => '2', 'gross' => '70'],
            ['user_id' => '5', 'name' => null, 'orders_count' => '0', 'gross' => '0'],
        ];
        $this->data['registers'] = [
            ['register_id' => '1', 'name' => '', 'code' => null, 'orders_count' => '3', 'gross' => '100'],
        ];
        $this->data['hours'] = [
            ['created_at' => '2026-03-01 09:15:00', 'grand_total' => '10'],
            ['created_at' => '2026-03-01 09:45:00', 'grand_total' => '15.5'],
            ['created_at' => '2026-03-01 23:30:00', 'grand_total' => '5'],
            ['created_at' => 'not a date', 'grand_total' => '99'],
        ];

        $from = new \DateTime('2026-03-01 00:00:00', new \DateTimeZone('Europe/Berlin'));
        $to = new \DateTimeImmutable('2026-03-01 23:59:59', new \DateTimeZone('Europe/Berlin'));
        $report = $this->makeService('Europe/Berlin')->salesSummary($from, $to, 1);

        $this->assertSame('2026-02-28 23:00:00', $report['from']);
        $this->assertSame('2026-03-01 22:59:59', $report['to']);
        $this->assertSame(3, $report['orders_count']);
        $this->assertSame(100.0001, $report['gross']);
        $this->assertSame(33.3334, $report['average_order']);
        $this->assertSame([['method_code' => 'cash', 'method_title' => 'Cash', 'amount' => 60.5, 'orders_count' => 2]], $report['by_payment_method']);
        $this->assertSame(['user_id' => 4, 'name' => 'Ann', 'orders_count' => 2, 'gross' => 70.0, 'average_order' => 35.0], $report['by_cashier'][0]);
        $this->assertSame('User #5', $report['by_cashier'][1]['name']);
        $this->assertSame(0.0, $report['by_cashier'][1]['average_order']);
        $this->assertSame('Register #1', $report['by_register'][0]['name']);
        $this->assertNull($report['by_register'][0]['code']);
        $this->assertCount(24, $report['by_hour']);
        $this->assertSame(['hour' => 10, 'orders_count' => 2, 'gross' => 25.5], $report['by_hour'][10]);
        $this->assertSame(['hour' => 0, 'orders_count' => 1, 'gross' => 5.0], $report['by_hour'][0]);
        foreach ($this->wheres() as $where) {
            $this->assertSame('2026-02-28 23:00:00', $where['so.created_at >= ?']);
            $this->assertSame(1, $where['ppo.register_id = ?']);
            $this->assertArrayNotHasKey('ppo.session_id = ?', $where);
        }
    }

    public function testSalesSummaryWithoutOrdersHasZeroAverageAndNoRegisterFilter(): void
    {
        $report = $this->makeService()->salesSummary(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'), 0);

        $this->assertSame(0, $report['orders_count']);
        $this->assertSame(0.0, $report['average_order']);
        $this->assertSame(0, array_sum(array_column($report['by_hour'], 'orders_count')));
        foreach ($this->wheres() as $where) {
            $this->assertArrayNotHasKey('ppo.register_id = ?', $where);
        }
    }

    public function testSessionReportIncludesSnapshotMovementsAndSessionDetails(): void
    {
        $session = $this->makeModel(Session::class, 'session_id', [
            'session_id' => 7, 'register_id' => 2, 'user_id' => 4, 'status' => 'closed',
            'opening_float' => '100', 'expected_cash' => '150', 'counted_cash' => '149.5', 'over_short' => '-0.5',
            'totals_json' => '{"gross":50}', 'opened_at' => '2026-01-01 08:00:00', 'closed_at' => '2026-01-01 18:00:00',
            'note' => 'ok',
        ]);
        $this->sessionRepository->method('getById')->with(7)->willReturn($session);
        $this->registerRepository->method('getById')->with(2)->willReturn(
            $this->makeModel(Register::class, 'register_id', ['name' => 'Front', 'code' => 'R1'])
        );
        $this->userRepository->method('getById')->with(4)->willReturn(
            $this->makeModel(PosUser::class, 'user_id', ['name' => 'Ann'])
        );
        $this->data['totals'] = ['orders_count' => '2', 'gross' => '50'];
        $this->data['movements'] = [
            ['movement_id' => '1', 'session_id' => '7', 'user_id' => '4', 'type' => 'float', 'amount' => '100', 'reason' => null, 'order_id' => null, 'created_at' => 't1'],
            ['movement_id' => '2', 'session_id' => '7', 'user_id' => '4', 'type' => 'sale', 'amount' => '50', 'reason' => 'Order', 'order_id' => '33', 'created_at' => 't2'],
        ];

        $report = $this->makeService()->sessionReport(7);

        $this->assertSame(['gross' => 50], $report['z_snapshot']);
        $this->assertSame(25.0, $report['average_order']);
        $this->assertSame('Front', $report['session']['register_name']);
        $this->assertSame('R1', $report['session']['register_code']);
        $this->assertSame('Ann', $report['session']['user_name']);
        $this->assertSame(-0.5, $report['session']['over_short']);
        $this->assertSame(149.5, $report['session']['counted_cash']);
        $this->assertNull($report['movements'][0]['order_id']);
        $this->assertNull($report['movements'][0]['reason']);
        $this->assertSame(33, $report['movements'][1]['order_id']);
        $this->assertSame(50.0, $report['movements'][1]['amount']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $report['generated_at']);
        $this->assertArrayNotHasKey('by_register', $report);
        foreach ($this->wheres() as $where) {
            $this->assertArrayNotHasKey('so.created_at >= ?', $where);
            $this->assertSame(7, $where['ppo.session_id = ?'] ?? $where['pcm.session_id = ?']);
        }
    }

    public function testOpenSessionHasNoSnapshotAndToleratesMissingRegisterAndUser(): void
    {
        $session = $this->makeModel(Session::class, 'session_id', [
            'session_id' => 8, 'register_id' => 2, 'user_id' => 4, 'status' => 'open', 'totals_json' => '{"gross":1}',
        ]);
        $this->sessionRepository->method('getById')->willReturn($session);
        $this->registerRepository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));
        $this->userRepository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));

        $report = $this->makeService()->sessionReport(8);

        $this->assertNull($report['z_snapshot']);
        $this->assertNull($report['session']['register_name']);
        $this->assertNull($report['session']['user_name']);
        $this->assertNull($report['session']['expected_cash']);
        $this->assertSame(0.0, $report['session']['opening_float']);
    }

    public function testClosedSessionWithInvalidSnapshotJsonYieldsNull(): void
    {
        $this->sessionRepository->method('getById')->willReturn(
            $this->makeModel(Session::class, 'session_id', ['status' => 'closed', 'totals_json' => 'oops'])
        );
        $this->registerRepository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));
        $this->userRepository->method('getById')->willThrowException(new NoSuchEntityException(__('x')));

        $this->assertNull($this->makeService()->sessionReport(8)['z_snapshot']);
    }
}
