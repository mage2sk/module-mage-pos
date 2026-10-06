<?php
declare(strict_types=1);

namespace Panth\MagePos\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Panth\MagePos\Api\Data\RegisterInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Service\ReportService;

class Report extends Template
{
    private const DATE_PARAM_FORMAT = 'Y-m-d';

    private ?array $summary = null;

    private ?array $registers = null;

    public function __construct(
        Context $context,
        private readonly ReportService $reportService,
        private readonly RegisterRepositoryInterface $registerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSummary(): array
    {
        if ($this->summary === null) {
            $this->summary = $this->reportService->salesSummary(
                $this->getFromDate(),
                $this->getToDate(),
                $this->getRegisterId()
            );
        }

        return $this->summary;
    }

    public function getFromDate(): \DateTimeImmutable
    {
        return $this->buildDate((string)$this->getRequest()->getParam('from', ''))
            ->setTime(0, 0, 0);
    }

    public function getToDate(): \DateTimeImmutable
    {
        $to = $this->buildDate((string)$this->getRequest()->getParam('to', ''))
            ->setTime(23, 59, 59);
        $from = $this->getFromDate();

        return $to < $from ? $from->setTime(23, 59, 59) : $to;
    }

    public function getFromParam(): string
    {
        return $this->getFromDate()->format(self::DATE_PARAM_FORMAT);
    }

    public function getToParam(): string
    {
        return $this->getToDate()->format(self::DATE_PARAM_FORMAT);
    }

    public function getRegisterId(): ?int
    {
        $registerId = (int)$this->getRequest()->getParam('register_id', 0);

        return $registerId > 0 ? $registerId : null;
    }

    public function getRegisters(): array
    {
        if ($this->registers === null) {
            $this->registers = [];
            $sortOrder = $this->sortOrderBuilder
                ->setField(RegisterInterface::NAME)
                ->setAscendingDirection()
                ->create();
            $searchCriteria = $this->searchCriteriaBuilder
                ->addSortOrder($sortOrder)
                ->create();
            foreach ($this->registerRepository->getList($searchCriteria)->getItems() as $register) {
                if ($register instanceof RegisterInterface) {
                    $this->registers[(int)$register->getRegisterId()] =
                        $register->getName() . ' (' . $register->getCode() . ')';
                }
            }
        }

        return $this->registers;
    }

    public function getFormUrl(): string
    {
        return $this->getUrl('panth_pos/report/index');
    }

    public function formatPrice(float $amount): string
    {
        return $this->priceCurrency->format($amount, false, PriceCurrencyInterface::DEFAULT_PRECISION);
    }

    private function buildDate(string $value): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone($this->_localeDate->getConfigTimezone());
        if ($value !== '') {
            $date = \DateTimeImmutable::createFromFormat(self::DATE_PARAM_FORMAT, $value, $timezone);
            if ($date instanceof \DateTimeImmutable
                && $date->format(self::DATE_PARAM_FORMAT) === $value
            ) {
                return $date;
            }
        }

        return new \DateTimeImmutable('now', $timezone);
    }
}
