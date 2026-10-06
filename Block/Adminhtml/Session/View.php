<?php
declare(strict_types=1);

namespace Panth\MagePos\Block\Adminhtml\Session;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Theme\Block\Html\Pager;
use Panth\MagePos\Api\Data\CashMovementInterface;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Model\ResourceModel\CashMovement\Collection as CashMovementCollection;
use Panth\MagePos\Model\ResourceModel\CashMovement\CollectionFactory as CashMovementCollectionFactory;
use Panth\MagePos\Service\PosSessionService;

class View extends Template
{
    public const MOVEMENTS_PAGE_SIZE = 20;

    public const MOVEMENTS_PAGE_VAR = 'p';

    private ?SessionInterface $session = null;

    private bool $sessionLoaded = false;

    private ?array $report = null;

    private ?CashMovementCollection $movementsCollection = null;

    private array $userNames = [];

    public function __construct(
        Context $context,
        private readonly SessionRepositoryInterface $sessionRepository,
        private readonly RegisterRepositoryInterface $registerRepository,
        private readonly PosUserRepositoryInterface $posUserRepository,
        private readonly CashMovementCollectionFactory $cashMovementCollectionFactory,
        private readonly PosSessionService $posSessionService,
        private readonly SerializerInterface $serializer,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSession(): ?SessionInterface
    {
        if (!$this->sessionLoaded) {
            $this->sessionLoaded = true;
            $sessionId = (int)$this->getRequest()->getParam('session_id');
            if ($sessionId > 0) {
                try {
                    $this->session = $this->sessionRepository->getById($sessionId);
                } catch (NoSuchEntityException $e) {
                    $this->session = null;
                }
            }
        }

        return $this->session;
    }

    public function isOpen(): bool
    {
        $session = $this->getSession();

        return $session !== null && $session->getStatus() === SessionInterface::STATUS_OPEN;
    }

    public function getRegisterName(): ?string
    {
        $session = $this->getSession();
        if ($session === null) {
            return null;
        }
        try {
            $register = $this->registerRepository->getById((int)$session->getRegisterId());

            return sprintf('%s (%s)', $register->getName(), $register->getCode());
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    public function getCashierName(): ?string
    {
        $session = $this->getSession();

        return $session !== null ? $this->resolveUserName((int)$session->getUserId()) : null;
    }

    public function resolveUserName(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        if (!array_key_exists($userId, $this->userNames)) {
            try {
                $this->userNames[$userId] = $this->posUserRepository->getById($userId)->getName();
            } catch (NoSuchEntityException $e) {
                $this->userNames[$userId] = (string)__('User #%1', $userId);
            }
        }

        return $this->userNames[$userId];
    }

    public function getMovementsCollection(): ?CashMovementCollection
    {
        if ($this->movementsCollection === null) {
            $session = $this->getSession();
            if ($session === null) {
                return null;
            }
            $collection = $this->cashMovementCollectionFactory->create();
            $collection->addFieldToFilter(
                CashMovementInterface::SESSION_ID,
                (int)$session->getSessionId()
            );
            $collection->setOrder(CashMovementInterface::MOVEMENT_ID, 'ASC');
            $collection->setPageSize(self::MOVEMENTS_PAGE_SIZE);
            $collection->setCurPage(
                max(1, (int)$this->getRequest()->getParam(self::MOVEMENTS_PAGE_VAR, 1))
            );
            $this->movementsCollection = $collection;
        }

        return $this->movementsCollection;
    }

    public function getMovements(): array
    {
        $collection = $this->getMovementsCollection();

        return $collection !== null ? array_values($collection->getItems()) : [];
    }

    public function getMovementsTotal(): int
    {
        $collection = $this->getMovementsCollection();

        return $collection !== null ? (int)$collection->getSize() : 0;
    }

    public function getMovementsPagerHtml(): string
    {
        return $this->getChildHtml('movements_pager');
    }

    protected function _prepareLayout()
    {
        parent::_prepareLayout();

        $collection = $this->getMovementsCollection();
        if ($collection !== null) {
            $pager = $this->getLayout()->createBlock(
                Pager::class,
                'panth_pos.session.view.movements.pager'
            );
            $pager->setTemplate('Panth_MagePos::session/pager.phtml')
                ->setPageVarName(self::MOVEMENTS_PAGE_VAR)
                ->setShowPerPage(false)
                ->setAvailableLimit([self::MOVEMENTS_PAGE_SIZE => self::MOVEMENTS_PAGE_SIZE])
                ->setLimit(self::MOVEMENTS_PAGE_SIZE);
            $pager->setCollection($collection);
            $this->setChild('movements_pager', $pager);
        }

        return $this;
    }

    public function getReport(): array
    {
        if ($this->report !== null) {
            return $this->report;
        }
        $this->report = [];

        $session = $this->getSession();
        if ($session === null) {
            return $this->report;
        }

        if ($session->getStatus() === SessionInterface::STATUS_CLOSED
            && $session->getTotalsJson() !== null
            && $session->getTotalsJson() !== ''
        ) {
            try {
                $decoded = $this->serializer->unserialize($session->getTotalsJson());
                if (is_array($decoded)) {
                    $this->report = $decoded;

                    return $this->report;
                }
            } catch (\InvalidArgumentException $e) {
            }
        }

        try {
            $this->report = $this->posSessionService->xReport((int)$session->getSessionId());
        } catch (\Exception $e) {
            $this->report = [];
        }

        return $this->report;
    }

    public function getPaymentTotals(): array
    {
        $rows = $this->getReport()['by_payment_method'] ?? [];

        return is_array($rows) ? $rows : [];
    }

    public function getCashSummary(): array
    {
        $cash = $this->getReport()['cash'] ?? [];
        if (!is_array($cash)) {
            $cash = [];
        }
        $session = $this->getSession();

        return [
            'opening_float' => (float)($cash['opening_float'] ?? ($session !== null ? $session->getOpeningFloat() : 0)),
            'sales' => (float)($cash['sales'] ?? 0),
            'refunds' => (float)($cash['refunds'] ?? 0),
            'in' => (float)($cash['in'] ?? 0),
            'out' => (float)($cash['out'] ?? 0),
            'expected' => isset($cash['expected']) ? (float)$cash['expected']
                : ($session !== null && $session->getExpectedCash() !== null ? (float)$session->getExpectedCash() : null),
            'counted' => isset($cash['counted']) ? (float)$cash['counted']
                : ($session !== null && $session->getCountedCash() !== null ? (float)$session->getCountedCash() : null),
            'over_short' => isset($cash['over_short']) ? (float)$cash['over_short']
                : ($session !== null && $session->getOverShort() !== null ? (float)$session->getOverShort() : null),
        ];
    }

    public function getOrdersCount(): int
    {
        return (int)($this->getReport()['orders_count'] ?? 0);
    }

    public function getGross(): float
    {
        return (float)($this->getReport()['gross'] ?? 0);
    }

    public function formatMoney(float|int|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '-';
        }

        return $this->priceCurrency->format((float)$amount, false);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function getOrderViewUrl(int $orderId): string
    {
        return $this->getUrl('sales/order/view', ['order_id' => $orderId]);
    }

    public function getForceCloseUrl(): string
    {
        $session = $this->getSession();

        return $this->getUrl(
            '*/*/forceclose',
            ['session_id' => $session !== null ? (int)$session->getSessionId() : 0]
        );
    }
}
