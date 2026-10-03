<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Block\Adminhtml;

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../PosTestHelperTrait.php';

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Block\Adminhtml\Report;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Service\ReportService;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ReportTest extends TestCase
{
    use PosTestHelperTrait;

    private array $params = [];
    private ReportService&MockObject $reportService;
    private RegisterRepositoryInterface&MockObject $registerRepository;

    protected function setUp(): void
    {
        $this->installObjectManager();
        $this->params = [];
        $this->reportService = $this->createMock(ReportService::class);
        $this->registerRepository = $this->createMock(RegisterRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $this->resetObjectManager();
    }

    private function makeBlock(): Report
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $this->params[$k] ?? $d);
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('Europe/London');
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(static fn ($route) => 'admin/' . $route);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getLocaleDate')->willReturn($timezone);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(static fn ($amount) => 'GBP' . number_format((float) $amount, 2));

        return new Report(
            $context,
            $this->reportService,
            $this->registerRepository,
            $this->makeCriteriaBuilder(),
            $this->makeSortOrderBuilder(),
            $priceCurrency
        );
    }

    public function testValidDateParamsDefineInclusiveRange(): void
    {
        $this->params = ['from' => '2026-03-01', 'to' => '2026-03-05', 'register_id' => '4'];
        $block = $this->makeBlock();

        $this->assertSame('2026-03-01 00:00:00', $block->getFromDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-05 23:59:59', $block->getToDate()->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/London', $block->getFromDate()->getTimezone()->getName());
        $this->assertSame('2026-03-01', $block->getFromParam());
        $this->assertSame('2026-03-05', $block->getToParam());
        $this->assertSame(4, $block->getRegisterId());
    }

    public function testInvalidDatesFallBackToToday(): void
    {
        $this->params = ['from' => '2026-02-30', 'to' => 'yesterday', 'register_id' => '0'];
        $block = $this->makeBlock();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d');

        $this->assertSame($today, $block->getFromParam());
        $this->assertSame($today, $block->getToParam());
        $this->assertNull($block->getRegisterId());
    }

    public function testToBeforeFromIsClampedToEndOfFromDay(): void
    {
        $this->params = ['from' => '2026-03-10', 'to' => '2026-03-01'];

        $this->assertSame('2026-03-10 23:59:59', $this->makeBlock()->getToDate()->format('Y-m-d H:i:s'));
    }

    public function testSummaryIsComputedOnceWithRequestFilters(): void
    {
        $this->params = ['from' => '2026-03-01', 'to' => '2026-03-02', 'register_id' => '2'];
        $this->reportService->expects($this->once())->method('salesSummary')
            ->with(
                $this->callback(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d H:i:s') === '2026-03-01 00:00:00'),
                $this->callback(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d H:i:s') === '2026-03-02 23:59:59'),
                2
            )
            ->willReturn(['orders_count' => 3]);
        $block = $this->makeBlock();

        $this->assertSame(['orders_count' => 3], $block->getSummary());
        $this->assertSame(['orders_count' => 3], $block->getSummary());
    }

    public function testRegistersAreListedByIdWithCode(): void
    {
        $this->registerRepository->expects($this->once())->method('getList')->willReturn($this->makeSearchResults([
            $this->makeModel(Register::class, 'register_id', ['register_id' => 2, 'name' => 'Front', 'code' => 'R1']),
            new \stdClass(),
            $this->makeModel(Register::class, 'register_id', ['register_id' => 5, 'name' => 'Back', 'code' => 'R2']),
        ]));
        $block = $this->makeBlock();

        $this->assertSame([2 => 'Front (R1)', 5 => 'Back (R2)'], $block->getRegisters());
        $this->assertSame([2 => 'Front (R1)', 5 => 'Back (R2)'], $block->getRegisters());
    }

    public function testFormUrlAndPriceFormatting(): void
    {
        $block = $this->makeBlock();

        $this->assertSame('admin/panth_pos/report/index', $block->getFormUrl());
        $this->assertSame('GBP12.50', $block->formatPrice(12.5));
    }
}
