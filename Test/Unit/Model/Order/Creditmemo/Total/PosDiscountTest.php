<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Order\Creditmemo\Total;

require_once __DIR__ . '/../../../../autoload.php';

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Payment;
use Panth\MagePos\Model\Order\Creditmemo\Total\PosDiscount;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PosDiscountTest extends TestCase
{
    private function makeOrder(array $data, ?array $additionalInfo = null): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment'])
            ->getMock();
        $order->setData($data);
        $payment = null;
        if ($additionalInfo !== null) {
            $payment = $this->createStub(Payment::class);
            $payment->method('getAdditionalInformation')->willReturn($additionalInfo);
        }
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function makeCreditmemo(?Order $order, array $data): Creditmemo
    {
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder'])
            ->getMock();
        $creditmemo->setData($data);
        $creditmemo->method('getOrder')->willReturn($order);

        return $creditmemo;
    }

    public function testStoredDiscountIsAppliedProportionallyToRefundedSubtotal(): void
    {
        $order = $this->makeOrder(['subtotal' => 100, 'base_subtotal' => 100], [
            'pos_discount_amount' => '10', 'base_pos_discount_amount' => '20',
        ]);
        $creditmemo = $this->makeCreditmemo($order, [
            'subtotal' => 50, 'grand_total' => 50, 'base_grand_total' => 50,
        ]);

        $result = (new PosDiscount())->collect($creditmemo);

        $this->assertInstanceOf(PosDiscount::class, $result);
        $this->assertSame(45.0, $creditmemo->getGrandTotal());
        $this->assertSame(40.0, $creditmemo->getBaseGrandTotal());
    }

    public function testResidualDiscountIsDerivedFromOrderTotalsWhenNotStored(): void
    {
        $order = $this->makeOrder([
            'subtotal' => 100, 'tax_amount' => 20, 'discount_amount' => -10, 'shipping_amount' => 5,
            'shipping_tax_amount' => 1, 'grand_total' => 106,
            'base_subtotal' => 100, 'base_tax_amount' => 20, 'base_discount_amount' => -10,
            'base_shipping_amount' => 5, 'base_shipping_tax_amount' => 1, 'base_grand_total' => 116,
        ], ['other' => 'x']);
        $creditmemo = $this->makeCreditmemo($order, [
            'subtotal' => 100, 'grand_total' => 116, 'base_grand_total' => 116,
        ]);

        (new PosDiscount())->collect($creditmemo);

        $this->assertSame(106.0, $creditmemo->getGrandTotal());
        $this->assertSame(116, $creditmemo->getBaseGrandTotal());
    }

    public function testReductionNeverExceedsCurrentGrandTotal(): void
    {
        $order = $this->makeOrder(['subtotal' => 0], ['pos_discount_amount' => 30, 'base_pos_discount_amount' => 30]);
        $creditmemo = $this->makeCreditmemo($order, ['subtotal' => 0, 'grand_total' => 12, 'base_grand_total' => 0]);

        (new PosDiscount())->collect($creditmemo);

        $this->assertSame(0.0, $creditmemo->getGrandTotal());
        $this->assertSame(0, $creditmemo->getBaseGrandTotal());
    }

    public static function noopProvider(): array
    {
        return [
            'no discount' => [['subtotal' => 50, 'grand_total' => 50, 'base_subtotal' => 50, 'base_grand_total' => 50], null, 50],
            'non numeric stored values fall back to zero residual' => [
                ['subtotal' => 50, 'grand_total' => 50, 'base_subtotal' => 50, 'base_grand_total' => 50],
                ['pos_discount_amount' => 'abc', 'base_pos_discount_amount' => 'x'],
                50,
            ],
            'zero refund share' => [['subtotal' => 100], ['pos_discount_amount' => 10, 'base_pos_discount_amount' => 10], 0],
        ];
    }

    #[DataProvider('noopProvider')]
    public function testNothingChangesWithoutDiscountOrShare(array $orderData, ?array $info, int $cmSubtotal): void
    {
        $creditmemo = $this->makeCreditmemo($this->makeOrder($orderData, $info), [
            'subtotal' => $cmSubtotal, 'grand_total' => 50, 'base_grand_total' => 50,
        ]);

        (new PosDiscount())->collect($creditmemo);

        $this->assertSame(50, $creditmemo->getGrandTotal());
        $this->assertSame(50, $creditmemo->getBaseGrandTotal());
    }

    public function testCreditmemoWithoutOrderIsIgnored(): void
    {
        $creditmemo = $this->makeCreditmemo(null, ['grand_total' => 50]);

        (new PosDiscount())->collect($creditmemo);

        $this->assertSame(50, $creditmemo->getGrandTotal());
    }
}
