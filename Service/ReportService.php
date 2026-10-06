<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;

class ReportService
{
    private const DB_DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly SessionRepositoryInterface $sessionRepository,
        private readonly RegisterRepositoryInterface $registerRepository,
        private readonly PosUserRepositoryInterface $posUserRepository,
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function salesSummary(\DateTimeInterface $from, \DateTimeInterface $to, ?int $registerId): array
    {
        $fromUtc = $this->toUtcString($from);
        $toUtc = $this->toUtcString($to);

        $totals = $this->fetchTotals($fromUtc, $toUtc, $registerId, null);
        $ordersCount = $totals['orders_count'];
        $gross = $totals['gross'];

        return [
            'from' => $fromUtc,
            'to' => $toUtc,
            'register_id' => $registerId,
            'orders_count' => $ordersCount,
            'gross' => $gross,
            'average_order' => $ordersCount > 0 ? round($gross / $ordersCount, 4) : 0.0,
            'by_payment_method' => $this->fetchByPaymentMethod($fromUtc, $toUtc, $registerId, null),
            'by_cashier' => $this->fetchByCashier($fromUtc, $toUtc, $registerId, null),
            'by_register' => $this->fetchByRegister($fromUtc, $toUtc, $registerId, null),
            'by_hour' => $this->fetchByHour($fromUtc, $toUtc, $registerId, null),
        ];
    }

    public function sessionReport(int $sessionId): array
    {
        $session = $this->sessionRepository->getById($sessionId);

        $snapshot = null;
        if ($session->getStatus() === SessionInterface::STATUS_CLOSED && $session->getTotalsJson() !== null) {
            $decoded = json_decode($session->getTotalsJson(), true);
            if (is_array($decoded)) {
                $snapshot = $decoded;
            }
        }

        $totals = $this->fetchTotals(null, null, null, $sessionId);
        $ordersCount = $totals['orders_count'];
        $gross = $totals['gross'];

        return [
            'session' => $this->buildSessionPayload($session),
            'orders_count' => $ordersCount,
            'gross' => $gross,
            'average_order' => $ordersCount > 0 ? round($gross / $ordersCount, 4) : 0.0,
            'by_payment_method' => $this->fetchByPaymentMethod(null, null, null, $sessionId),
            'by_cashier' => $this->fetchByCashier(null, null, null, $sessionId),
            'by_hour' => $this->fetchByHour(null, null, null, $sessionId),
            'movements' => $this->fetchMovements($sessionId),
            'z_snapshot' => $snapshot,
            'generated_at' => gmdate(self::DB_DATE_FORMAT),
        ];
    }

    private function fetchTotals(?string $fromUtc, ?string $toUtc, ?int $registerId, ?int $sessionId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['ppo' => $this->resourceConnection->getTableName('panth_pos_order')],
                ['orders_count' => new \Zend_Db_Expr('COUNT(*)')]
            )
            ->join(
                ['so' => $this->resourceConnection->getTableName('sales_order')],
                'so.entity_id = ppo.order_id',
                ['gross' => new \Zend_Db_Expr('COALESCE(SUM(so.grand_total), 0)')]
            );
        $this->applyFilters($select, $fromUtc, $toUtc, $registerId, $sessionId);

        $row = $connection->fetchRow($select) ?: [];

        return [
            'orders_count' => (int)($row['orders_count'] ?? 0),
            'gross' => round((float)($row['gross'] ?? 0), 4),
        ];
    }

    private function fetchByPaymentMethod(?string $fromUtc, ?string $toUtc, ?int $registerId, ?int $sessionId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['pp' => $this->resourceConnection->getTableName('panth_pos_order_payment')],
                [
                    'method_code' => 'pp.method_code',
                    'method_title' => new \Zend_Db_Expr('MAX(pp.method_title)'),
                    'amount' => new \Zend_Db_Expr('SUM(pp.amount)'),
                    'orders_count' => new \Zend_Db_Expr('COUNT(DISTINCT pp.pos_order_id)'),
                ]
            )
            ->join(
                ['ppo' => $this->resourceConnection->getTableName('panth_pos_order')],
                'ppo.pos_order_id = pp.pos_order_id',
                []
            )
            ->join(
                ['so' => $this->resourceConnection->getTableName('sales_order')],
                'so.entity_id = ppo.order_id',
                []
            )
            ->group('pp.method_code')
            ->order('amount DESC');
        $this->applyFilters($select, $fromUtc, $toUtc, $registerId, $sessionId);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'method_code' => (string)$row['method_code'],
                'method_title' => (string)$row['method_title'],
                'amount' => round((float)$row['amount'], 4),
                'orders_count' => (int)$row['orders_count'],
            ];
        }

        return $rows;
    }

    private function fetchByCashier(?string $fromUtc, ?string $toUtc, ?int $registerId, ?int $sessionId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['ppo' => $this->resourceConnection->getTableName('panth_pos_order')],
                [
                    'user_id' => 'ppo.pos_user_id',
                    'orders_count' => new \Zend_Db_Expr('COUNT(*)'),
                ]
            )
            ->join(
                ['so' => $this->resourceConnection->getTableName('sales_order')],
                'so.entity_id = ppo.order_id',
                ['gross' => new \Zend_Db_Expr('COALESCE(SUM(so.grand_total), 0)')]
            )
            ->joinLeft(
                ['ppu' => $this->resourceConnection->getTableName('panth_pos_user')],
                'ppu.user_id = ppo.pos_user_id',
                ['name' => new \Zend_Db_Expr('MAX(ppu.name)')]
            )
            ->group('ppo.pos_user_id')
            ->order('gross DESC');
        $this->applyFilters($select, $fromUtc, $toUtc, $registerId, $sessionId);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $ordersCount = (int)$row['orders_count'];
            $gross = round((float)$row['gross'], 4);
            $rows[] = [
                'user_id' => (int)$row['user_id'],
                'name' => $row['name'] !== null && $row['name'] !== ''
                    ? (string)$row['name']
                    : (string)__('User #%1', (int)$row['user_id']),
                'orders_count' => $ordersCount,
                'gross' => $gross,
                'average_order' => $ordersCount > 0 ? round($gross / $ordersCount, 4) : 0.0,
            ];
        }

        return $rows;
    }

    private function fetchByRegister(?string $fromUtc, ?string $toUtc, ?int $registerId, ?int $sessionId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['ppo' => $this->resourceConnection->getTableName('panth_pos_order')],
                [
                    'register_id' => 'ppo.register_id',
                    'orders_count' => new \Zend_Db_Expr('COUNT(*)'),
                ]
            )
            ->join(
                ['so' => $this->resourceConnection->getTableName('sales_order')],
                'so.entity_id = ppo.order_id',
                ['gross' => new \Zend_Db_Expr('COALESCE(SUM(so.grand_total), 0)')]
            )
            ->joinLeft(
                ['ppr' => $this->resourceConnection->getTableName('panth_pos_register')],
                'ppr.register_id = ppo.register_id',
                [
                    'name' => new \Zend_Db_Expr('MAX(ppr.name)'),
                    'code' => new \Zend_Db_Expr('MAX(ppr.code)'),
                ]
            )
            ->group('ppo.register_id')
            ->order('gross DESC');
        $this->applyFilters($select, $fromUtc, $toUtc, $registerId, $sessionId);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $ordersCount = (int)$row['orders_count'];
            $gross = round((float)$row['gross'], 4);
            $rows[] = [
                'register_id' => (int)$row['register_id'],
                'name' => $row['name'] !== null && $row['name'] !== ''
                    ? (string)$row['name']
                    : (string)__('Register #%1', (int)$row['register_id']),
                'code' => $row['code'] !== null ? (string)$row['code'] : null,
                'orders_count' => $ordersCount,
                'gross' => $gross,
                'average_order' => $ordersCount > 0 ? round($gross / $ordersCount, 4) : 0.0,
            ];
        }

        return $rows;
    }

    private function fetchByHour(?string $fromUtc, ?string $toUtc, ?int $registerId, ?int $sessionId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['ppo' => $this->resourceConnection->getTableName('panth_pos_order')],
                []
            )
            ->join(
                ['so' => $this->resourceConnection->getTableName('sales_order')],
                'so.entity_id = ppo.order_id',
                ['created_at' => 'so.created_at', 'grand_total' => 'so.grand_total']
            );
        $this->applyFilters($select, $fromUtc, $toUtc, $registerId, $sessionId);

        $buckets = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $buckets[$hour] = ['hour' => $hour, 'orders_count' => 0, 'gross' => 0.0];
        }

        $utc = new \DateTimeZone('UTC');
        $storeTimezone = new \DateTimeZone($this->timezone->getConfigTimezone());
        foreach ($connection->fetchAll($select) as $row) {
            try {
                $createdAt = new \DateTimeImmutable((string)$row['created_at'], $utc);
            } catch (\Exception $e) {
                continue;
            }
            $hour = (int)$createdAt->setTimezone($storeTimezone)->format('G');
            $buckets[$hour]['orders_count']++;
            $buckets[$hour]['gross'] = round($buckets[$hour]['gross'] + (float)$row['grand_total'], 4);
        }

        return array_values($buckets);
    }

    private function fetchMovements(int $sessionId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['pcm' => $this->resourceConnection->getTableName('panth_pos_cash_movement')],
                ['movement_id', 'session_id', 'user_id', 'type', 'amount', 'reason', 'order_id', 'created_at']
            )
            ->where('pcm.session_id = ?', $sessionId)
            ->order('pcm.movement_id ASC');

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'movement_id' => (int)$row['movement_id'],
                'session_id' => (int)$row['session_id'],
                'user_id' => (int)$row['user_id'],
                'type' => (string)$row['type'],
                'amount' => round((float)$row['amount'], 4),
                'reason' => $row['reason'] !== null ? (string)$row['reason'] : null,
                'order_id' => $row['order_id'] !== null ? (int)$row['order_id'] : null,
                'created_at' => (string)$row['created_at'],
            ];
        }

        return $rows;
    }

    private function applyFilters(
        \Magento\Framework\DB\Select $select,
        ?string $fromUtc,
        ?string $toUtc,
        ?int $registerId,
        ?int $sessionId
    ): void {
        if ($fromUtc !== null) {
            $select->where('so.created_at >= ?', $fromUtc);
        }
        if ($toUtc !== null) {
            $select->where('so.created_at <= ?', $toUtc);
        }
        if ($registerId !== null && $registerId > 0) {
            $select->where('ppo.register_id = ?', $registerId);
        }
        if ($sessionId !== null && $sessionId > 0) {
            $select->where('ppo.session_id = ?', $sessionId);
        }
    }

    private function toUtcString(\DateTimeInterface $date): string
    {
        $immutable = $date instanceof \DateTimeImmutable
            ? $date
            : \DateTimeImmutable::createFromInterface($date);

        return $immutable->setTimezone(new \DateTimeZone('UTC'))->format(self::DB_DATE_FORMAT);
    }

    private function buildSessionPayload(SessionInterface $session): array
    {
        $registerName = null;
        $registerCode = null;
        try {
            $register = $this->registerRepository->getById((int)$session->getRegisterId());
            $registerName = $register->getName();
            $registerCode = $register->getCode();
        } catch (NoSuchEntityException $e) {
        }

        $userName = null;
        try {
            $userName = $this->posUserRepository->getById((int)$session->getUserId())->getName();
        } catch (NoSuchEntityException $e) {
        }

        return [
            'session_id' => (int)$session->getSessionId(),
            'register_id' => (int)$session->getRegisterId(),
            'register_name' => $registerName,
            'register_code' => $registerCode,
            'user_id' => (int)$session->getUserId(),
            'user_name' => $userName,
            'status' => $session->getStatus(),
            'opening_float' => (float)$session->getOpeningFloat(),
            'expected_cash' => $session->getExpectedCash() !== null ? (float)$session->getExpectedCash() : null,
            'counted_cash' => $session->getCountedCash() !== null ? (float)$session->getCountedCash() : null,
            'over_short' => $session->getOverShort() !== null ? (float)$session->getOverShort() : null,
            'opened_at' => $session->getOpenedAt(),
            'closed_at' => $session->getClosedAt(),
            'note' => $session->getNote(),
        ];
    }
}
