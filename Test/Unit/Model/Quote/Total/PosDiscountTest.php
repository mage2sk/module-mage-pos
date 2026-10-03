<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Quote\Total;

require_once __DIR__ . '/../../../autoload.php';

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Api\Data\ShippingInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Item;
use Panth\MagePos\Model\Quote\Total\PosDiscount;
use PHPUnit\Framework\MockObject\MockObject;
use Panth\MagePos\Test\Unit\MagicCallsTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PosDiscountTest extends TestCase
{
    use MagicCallsTrait;

    private PosDiscount $collector;

    private array $totalAmounts = [];

    private array $baseTotalAmounts = [];

    protected function setUp(): void
    {
        $this->collector = new PosDiscount();
        $this->totalAmounts = [];
        $this->baseTotalAmounts = [];
    }

    public function testConstructorRegistersCollectorCode(): void
    {
        $this->assertSame(PosDiscount::COLLECTOR_CODE, $this->collector->getCode());
    }

    public function testPercentDiscountAppliesToPostRuleSubtotal(): void
    {
        $total = $this->makeRealTotal(90.0, 90.0);
        $assignment = $this->makeAssignment(
            $this->makeAddress(-4.5, -4.5),
            withItem: true
        );
        $quote = $this->makeQuote(PosDiscount::TYPE_PERCENT, 10.0);

        $this->collector->collect($quote, $assignment, $total);

        $this->assertEqualsWithDelta(-8.55, $this->posDiscount(), 0.0001);
        $this->assertEqualsWithDelta(-8.55, $this->basePosDiscount(), 0.0001);
    }

    public function testPercentDiscountStacksOnCouponBase(): void
    {
        $total = $this->makeRealTotal(181.50, 181.50);
        $assignment = $this->makeAssignment(
            $this->makeAddress(-9.08, -9.08),
            withItem: true
        );
        $quote = $this->makeQuote(PosDiscount::TYPE_PERCENT, 5.0);

        $this->collector->collect($quote, $assignment, $total);

        $this->assertEqualsWithDelta(-8.621, $this->posDiscount(), 0.0001);
    }

    public function testPercentDiscountWithNoRuleDiscountUsesFullSubtotal(): void
    {
        $total = $this->makeRealTotal(200.0, 200.0);
        $assignment = $this->makeAssignment($this->makeAddress(0.0, 0.0), withItem: true);
        $quote = $this->makeQuote(PosDiscount::TYPE_PERCENT, 10.0);

        $this->collector->collect($quote, $assignment, $total);

        $this->assertEqualsWithDelta(-20.0, $this->posDiscount(), 0.0001);
    }

    public function testFixedDiscountIsCappedAtPostRuleSubtotal(): void
    {
        $total = $this->makeRealTotal(100.0, 100.0);
        $assignment = $this->makeAssignment($this->makeAddress(-60.0, -60.0), withItem: true);
        $quote = $this->makeQuote(PosDiscount::TYPE_FIXED, 50.0);

        $this->collector->collect($quote, $assignment, $total);

        $this->assertEqualsWithDelta(-40.0, $this->posDiscount(), 0.0001);
    }

    public function testNoDiscountWhenQuoteHasNoPosDiscountConfigured(): void
    {
        $total = $this->makeRealTotal(100.0, 100.0);
        $assignment = $this->makeAssignment($this->makeAddress(0.0, 0.0), withItem: true);
        $quote = $this->makeQuote('', 0.0);

        $this->collector->collect($quote, $assignment, $total);

        $this->assertSame(0.0, $this->posDiscount());
    }

    public function testNoDiscountWhenAssignmentHasNoItems(): void
    {
        $total = $this->makeRealTotal(100.0, 100.0);
        $assignment = $this->makeAssignment($this->makeAddress(-10.0, -10.0), withItem: false);
        $quote = $this->makeQuote(PosDiscount::TYPE_PERCENT, 10.0);

        $this->collector->collect($quote, $assignment, $total);

        $this->assertSame(0.0, $this->posDiscount());
    }

    private function makeQuote(string $type, float $value): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $quote->method('getData')->willReturnCallback(
            static function (string $key) use ($type, $value) {
                return match ($key) {
                    PosDiscount::QUOTE_FIELD_TYPE => $type,
                    PosDiscount::QUOTE_FIELD_VALUE => $value,
                    default => null,
                };
            }
        );

        return $quote;
    }

    private function makeRealTotal(float $subtotal, float $baseSubtotal): Total&MockObject
    {
        $this->totalAmounts = ['subtotal' => $subtotal, 'discount' => 0.0];
        $this->baseTotalAmounts = ['subtotal' => $baseSubtotal, 'discount' => 0.0];

        $total = $this->getMockBuilder(Total::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setTotalAmount',
                'getTotalAmount',
                'setBaseTotalAmount',
                'getBaseTotalAmount',
                '__call',
            ])
            ->getMock();

        $total->method('setTotalAmount')->willReturnCallback(function (string $code, $amount) use ($total) {
            $this->totalAmounts[$code] = (float)$amount;
            return $total;
        });
        $total->method('getTotalAmount')->willReturnCallback(
            fn (string $code) => $this->totalAmounts[$code] ?? 0.0
        );
        $total->method('setBaseTotalAmount')->willReturnCallback(function (string $code, $amount) use ($total) {
            $this->baseTotalAmounts[$code] = (float)$amount;
            return $total;
        });
        $total->method('getBaseTotalAmount')->willReturnCallback(
            fn (string $code) => $this->baseTotalAmounts[$code] ?? 0.0
        );
        $this->stubMagicCalls($total, [
            'getDiscountDescription' => '',
            'setDiscountDescription' => static fn () => $total,
        ]);

        return $total;
    }

    private function posDiscount(): float
    {
        return $this->totalAmounts[PosDiscount::COLLECTOR_CODE] ?? 0.0;
    }

    private function basePosDiscount(): float
    {
        return $this->baseTotalAmounts[PosDiscount::COLLECTOR_CODE] ?? 0.0;
    }

    private function makeAddress(float $discount, float $baseDiscount): Address&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();
        $this->stubMagicCalls($address, [
            'getDiscountAmount' => $discount,
            'getBaseDiscountAmount' => $baseDiscount,
        ]);

        return $address;
    }

    private function makeAssignment(Address $address, bool $withItem): ShippingAssignmentInterface&MockObject
    {
        $shipping = $this->createMock(ShippingInterface::class);
        $shipping->method('getAddress')->willReturn($address);

        $assignment = $this->createMock(ShippingAssignmentInterface::class);
        $assignment->method('getShipping')->willReturn($shipping);

        $items = [];
        if ($withItem) {
            $items[] = $this->getMockBuilder(Item::class)
                ->disableOriginalConstructor()
                ->getMock();
        }
        $assignment->method('getItems')->willReturn($items);

        return $assignment;
    }
}
