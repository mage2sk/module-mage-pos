<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\MagePos\Api\CashMovementRepositoryInterface;
use Panth\MagePos\Api\Data\CashMovementInterface;
use Panth\MagePos\Api\Data\CashMovementInterfaceFactory;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\Data\SessionInterfaceFactory;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;

class PosSessionService
{
    public const SESSION_KEY_SESSION_ID = 'panth_pos_session_id';

    public function __construct(
        private readonly SessionRepositoryInterface $sessionRepository,
        private readonly SessionInterfaceFactory $sessionFactory,
        private readonly CashMovementRepositoryInterface $cashMovementRepository,
        private readonly CashMovementInterfaceFactory $cashMovementFactory,
        private readonly RegisterRepositoryInterface $registerRepository,
        private readonly PosUserRepositoryInterface $posUserRepository,
        private readonly AuthService $authService,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly SessionManagerInterface $sessionManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    public function open(int $registerId, float $openingFloat, ?string $note): array
    {
        $user = $this->authService->requireUser();
        $this->authService->requirePermission('can_open_close');

        if ($openingFloat < 0) {
            throw new LocalizedException(__('The opening float cannot be negative.'));
        }

        try {
            $register = $this->registerRepository->getById($registerId);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Register with id "%1" does not exist.', $registerId));
        }
        if ((int)$register->getStatus() !== 1) {
            throw new LocalizedException(__('Register "%1" is disabled.', $register->getName()));
        }

        $existing = $this->findOpenSessionByRegister($registerId);
        if ($existing !== null) {
            throw new LocalizedException(
                __('Register "%1" already has an open session (#%2).', $register->getName(), $existing->getSessionId())
            );
        }

        $openingFloat = round($openingFloat, 4);

        $session = $this->sessionFactory->create();
        $session->setRegisterId($registerId)
            ->setUserId((int)$user->getUserId())
            ->setStatus(SessionInterface::STATUS_OPEN)
            ->setOpeningFloat($openingFloat)
            ->setOpenedAt($this->dateTime->gmtDate())
            ->setNote($note !== null && $note !== '' ? $note : null);
        $session = $this->sessionRepository->save($session);

        $this->recordMovement(
            (int)$session->getSessionId(),
            (int)$user->getUserId(),
            CashMovementInterface::TYPE_FLOAT,
            $openingFloat,
            'Opening float'
        );

        $this->sessionManager->setData(self::SESSION_KEY_SESSION_ID, (int)$session->getSessionId());

        return $this->buildSessionPayload($session);
    }

    public function current(): ?array
    {
        $session = $this->resolveCurrentSession();

        return $session !== null ? $this->buildSessionPayload($session) : null;
    }

    public function addMovement(string $type, float $amount, string $reason): array
    {
        $user = $this->authService->requireUser();
        $this->authService->requirePermission('can_cash_inout');

        if (!in_array($type, [CashMovementInterface::TYPE_IN, CashMovementInterface::TYPE_OUT], true)) {
            throw new LocalizedException(__('Invalid cash movement type "%1". Allowed: in, out.', $type));
        }
        if ($amount <= 0) {
            throw new LocalizedException(__('The cash movement amount must be greater than zero.'));
        }

        $session = $this->resolveCurrentSession();
        if ($session === null) {
            throw new LocalizedException(__('There is no open register session.'));
        }

        $signedAmount = round(
            $type === CashMovementInterface::TYPE_OUT ? -abs($amount) : abs($amount),
            4
        );

        $movement = $this->recordMovement(
            (int)$session->getSessionId(),
            (int)$user->getUserId(),
            $type,
            $signedAmount,
            $reason !== '' ? $reason : null
        );

        return [
            'movement' => $this->buildMovementPayload($movement),
            'expected_cash' => $this->expectedCash((int)$session->getSessionId()),
        ];
    }

    public function expectedCash(int $sessionId): float
    {
        $expected = 0.0;
        foreach ($this->loadMovements($sessionId) as $movement) {
            $expected += (float)$movement->getAmount();
        }

        return round($expected, 4);
    }

    public function xReport(int $sessionId): array
    {
        $session = $this->sessionRepository->getById($sessionId);

        return $this->buildReport($session);
    }

    public function close(int $sessionId, float $countedCash, ?string $note): array
    {
        $user = $this->authService->requireUser();
        $this->authService->requirePermission('can_open_close');

        $session = $this->sessionRepository->getById($sessionId);
        if ($session->getStatus() !== SessionInterface::STATUS_OPEN) {
            throw new LocalizedException(__('Session #%1 is already closed.', $sessionId));
        }
        if ($countedCash < 0) {
            throw new LocalizedException(__('The counted cash amount cannot be negative.'));
        }

        $countedCash = round($countedCash, 4);
        $expectedCash = $this->expectedCash($sessionId);
        $overShort = round($countedCash - $expectedCash, 4);
        $closedAt = $this->dateTime->gmtDate();

        $report = $this->buildReport($session);
        $report['cash']['expected'] = $expectedCash;
        $report['cash']['counted'] = $countedCash;
        $report['cash']['over_short'] = $overShort;
        $report['closed_at'] = $closedAt;

        $session->setStatus(SessionInterface::STATUS_CLOSED)
            ->setExpectedCash($expectedCash)
            ->setCountedCash($countedCash)
            ->setOverShort($overShort)
            ->setTotalsJson(json_encode($report))
            ->setClosedAt($closedAt);
        if ($note !== null && $note !== '') {
            $session->setNote($note);
        }
        $session = $this->sessionRepository->save($session);

        if ($countedCash > 0) {
            $this->recordMovement(
                $sessionId,
                (int)$user->getUserId(),
                CashMovementInterface::TYPE_CLOSE,
                -$countedCash,
                'Drawer emptied at session close'
            );
        }

        if ((int)$this->sessionManager->getData(self::SESSION_KEY_SESSION_ID) === $sessionId) {
            $this->sessionManager->unsetData(self::SESSION_KEY_SESSION_ID);
        }

        $report['session'] = $this->buildSessionPayload($session);

        return $report;
    }

    private function buildReport(SessionInterface $session): array
    {
        $sessionId = (int)$session->getSessionId();
        $isOpen = $session->getStatus() === SessionInterface::STATUS_OPEN;

        $movements = $this->loadMovements($sessionId);
        $byType = [
            CashMovementInterface::TYPE_FLOAT => 0.0,
            CashMovementInterface::TYPE_SALE => 0.0,
            CashMovementInterface::TYPE_REFUND => 0.0,
            CashMovementInterface::TYPE_IN => 0.0,
            CashMovementInterface::TYPE_OUT => 0.0,
            CashMovementInterface::TYPE_CLOSE => 0.0,
        ];
        $movementRows = [];
        $expectedLive = 0.0;
        foreach ($movements as $movement) {
            $amount = (float)$movement->getAmount();
            $type = $movement->getType();
            if (array_key_exists($type, $byType)) {
                $byType[$type] = round($byType[$type] + $amount, 4);
            }
            $expectedLive += $amount;
            $movementRows[] = $this->buildMovementPayload($movement);
        }

        $expected = $isOpen ? round($expectedLive, 4) : (float)($session->getExpectedCash() ?? round($expectedLive, 4));
        $orderTotals = $this->fetchOrderTotals($sessionId);

        return [
            'session' => $this->buildSessionPayload($session),
            'orders_count' => $orderTotals['orders_count'],
            'gross' => $orderTotals['gross'],
            'by_payment_method' => $this->fetchPaymentMethodTotals($sessionId),
            'cash' => [
                'opening_float' => (float)$session->getOpeningFloat(),
                'sales' => $byType[CashMovementInterface::TYPE_SALE],
                'refunds' => $byType[CashMovementInterface::TYPE_REFUND],
                'in' => $byType[CashMovementInterface::TYPE_IN],
                'out' => $byType[CashMovementInterface::TYPE_OUT],
                'expected' => $expected,
                'counted' => $session->getCountedCash() !== null ? (float)$session->getCountedCash() : null,
                'over_short' => $session->getOverShort() !== null ? (float)$session->getOverShort() : null,
            ],
            'movements' => $movementRows,
            'generated_at' => $this->dateTime->gmtDate(),
        ];
    }

    private function fetchOrderTotals(int $sessionId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['ppo' => $this->resourceConnection->getTableName('panth_pos_order')],
                ['orders_count' => new \Zend_Db_Expr('COUNT(*)')]
            )
            ->joinLeft(
                ['so' => $this->resourceConnection->getTableName('sales_order')],
                'so.entity_id = ppo.order_id',
                ['gross' => new \Zend_Db_Expr('COALESCE(SUM(so.grand_total), 0)')]
            )
            ->where('ppo.session_id = ?', $sessionId);

        $row = $connection->fetchRow($select) ?: [];

        return [
            'orders_count' => (int)($row['orders_count'] ?? 0),
            'gross' => round((float)($row['gross'] ?? 0), 2),
        ];
    }

    private function fetchPaymentMethodTotals(int $sessionId): array
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
            ->where('ppo.session_id = ?', $sessionId)
            ->group('pp.method_code')
            ->order('amount DESC');

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

    private function resolveCurrentSession(): ?SessionInterface
    {
        $user = $this->authService->getCurrentUser();
        if ($user === null) {
            return null;
        }

        $sessionId = (int)$this->sessionManager->getData(self::SESSION_KEY_SESSION_ID);
        if ($sessionId > 0) {
            try {
                $session = $this->sessionRepository->getById($sessionId);
                if ($session->getStatus() === SessionInterface::STATUS_OPEN) {
                    return $session;
                }
            } catch (NoSuchEntityException $e) {
            }
            $this->sessionManager->unsetData(self::SESSION_KEY_SESSION_ID);
        }

        $session = $this->findOpenSessionByUser((int)$user->getUserId());
        if ($session !== null) {
            $this->sessionManager->setData(self::SESSION_KEY_SESSION_ID, (int)$session->getSessionId());
        }

        return $session;
    }

    private function findOpenSessionByRegister(int $registerId): ?SessionInterface
    {
        return $this->findOpenSessionByField(SessionInterface::REGISTER_ID, $registerId);
    }

    private function findOpenSessionByUser(int $userId): ?SessionInterface
    {
        return $this->findOpenSessionByField(SessionInterface::USER_ID, $userId);
    }

    private function findOpenSessionByField(string $field, int $value): ?SessionInterface
    {
        $sortOrder = $this->sortOrderBuilder
            ->setField(SessionInterface::SESSION_ID)
            ->setDescendingDirection()
            ->create();
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter($field, $value)
            ->addFilter(SessionInterface::STATUS, SessionInterface::STATUS_OPEN)
            ->addSortOrder($sortOrder)
            ->setPageSize(1)
            ->setCurrentPage(1)
            ->create();
        $items = $this->sessionRepository->getList($searchCriteria)->getItems();
        $session = array_shift($items);

        return $session instanceof SessionInterface ? $session : null;
    }

    private function recordMovement(
        int $sessionId,
        int $userId,
        string $type,
        float $signedAmount,
        ?string $reason
    ): CashMovementInterface {
        $movement = $this->cashMovementFactory->create();
        $movement->setSessionId($sessionId)
            ->setUserId($userId)
            ->setType($type)
            ->setAmount(round($signedAmount, 4))
            ->setReason($reason);

        return $this->cashMovementRepository->save($movement);
    }

    private function loadMovements(int $sessionId): array
    {
        $sortOrder = $this->sortOrderBuilder
            ->setField(CashMovementInterface::MOVEMENT_ID)
            ->setAscendingDirection()
            ->create();
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(CashMovementInterface::SESSION_ID, $sessionId)
            ->addSortOrder($sortOrder)
            ->create();

        return array_values($this->cashMovementRepository->getList($searchCriteria)->getItems());
    }

    private function buildMovementPayload(CashMovementInterface $movement): array
    {
        return [
            'movement_id' => $movement->getMovementId(),
            'session_id' => $movement->getSessionId(),
            'user_id' => $movement->getUserId(),
            'type' => $movement->getType(),
            'amount' => (float)$movement->getAmount(),
            'reason' => $movement->getReason(),
            'order_id' => $movement->getOrderId(),
            'created_at' => $movement->getCreatedAt(),
        ];
    }

    private function buildSessionPayload(SessionInterface $session): array
    {
        $registerName = null;
        $registerCode = null;
        $storeId = null;
        try {
            $register = $this->registerRepository->getById((int)$session->getRegisterId());
            $registerName = $register->getName();
            $registerCode = $register->getCode();
            $storeId = (int)$register->getStoreId();
        } catch (NoSuchEntityException $e) {
        }

        $userName = null;
        try {
            $userName = $this->posUserRepository->getById((int)$session->getUserId())->getName();
        } catch (NoSuchEntityException $e) {
        }

        $isOpen = $session->getStatus() === SessionInterface::STATUS_OPEN;

        return [
            'session_id' => (int)$session->getSessionId(),
            'register_id' => (int)$session->getRegisterId(),
            'register_name' => $registerName,
            'register_code' => $registerCode,
            'store_id' => $storeId,
            'user_id' => (int)$session->getUserId(),
            'user_name' => $userName,
            'status' => $session->getStatus(),
            'opening_float' => (float)$session->getOpeningFloat(),
            'expected_cash' => $isOpen
                ? $this->expectedCash((int)$session->getSessionId())
                : ($session->getExpectedCash() !== null ? (float)$session->getExpectedCash() : null),
            'counted_cash' => $session->getCountedCash() !== null ? (float)$session->getCountedCash() : null,
            'over_short' => $session->getOverShort() !== null ? (float)$session->getOverShort() : null,
            'opened_at' => $session->getOpenedAt(),
            'closed_at' => $session->getClosedAt(),
            'note' => $session->getNote(),
        ];
    }
}
