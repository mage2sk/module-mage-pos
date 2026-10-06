<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\BlockFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Api\Data\RegisterInterface;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Model\PosOrder;
use Panth\MagePos\Model\PosOrderPayment;
use Panth\MagePos\Model\ResourceModel\PosOrder\Collection as PosOrderCollection;
use Panth\MagePos\Model\ResourceModel\PosOrder\CollectionFactory as PosOrderCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\Collection as PosOrderPaymentCollection;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\CollectionFactory as PosOrderPaymentCollectionFactory;
use Panth\MagePos\Service\ReceiptService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ReceiptServiceTest extends TestCase
{
    private const ORDER_ID = 10;
    private const GUEST_EMAIL = 'pos-guest@example.com';

    private OrderRepositoryInterface&MockObject $orderRepository;
    private PosOrderCollectionFactory&MockObject $posOrderCollectionFactory;
    private PosOrderPaymentCollectionFactory&MockObject $posOrderPaymentCollectionFactory;
    private RegisterRepositoryInterface&MockObject $registerRepository;
    private PosUserRepositoryInterface&MockObject $posUserRepository;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private float $grandTotal = 116.0;
    private float $shippingAmount = 0.0;

    public function testGetReceiptDataBuildsTheFullReceiptShape(): void
    {
        $service = $this->makeService();
        $this->givenPosOrderExists();

        $data = $service->getReceiptData(self::ORDER_ID);

        $this->assertSame(self::ORDER_ID, $data['order_id']);
        $this->assertSame('100000001', $data['increment_id']);
        $this->assertSame('main-12-3', $data['receipt_number']);
        $this->assertSame('Acme Store', $data['store_name']);
        $this->assertSame('Main Register', $data['register_name']);
        $this->assertSame('main', $data['register_code']);
        $this->assertSame('Alice', $data['cashier_name']);
        $this->assertSame('alice', $data['cashier_username']);

        $this->assertSame('Welcome!', $data['header']);
        $this->assertSame('Thank you for your purchase!', $data['footer']);

        $this->assertSame('', $data['customer_name']);
        $this->assertSame('', $data['customer_email']);

        $this->assertCount(1, $data['items']);
        $item = $data['items'][0];
        $this->assertSame('2', $item['qty_formatted']);
        $this->assertSame(58.0, $item['price']);

        $this->assertSame('EUR 60.00', $item['original_price_formatted']);
        $this->assertTrue($item['is_custom']);
        $this->assertSame('engraved', $item['note']);

        $this->assertSame(100.0, $data['totals']['subtotal']);
        $this->assertSame(5.0, $data['totals']['discount']);
        $this->assertSame('-EUR 5.00', $data['totals']['discount_formatted']);
        $this->assertSame(21.0, $data['totals']['tax']);
        $this->assertSame(116.0, $data['totals']['grand_total']);

        $this->assertTrue($data['show_tax_breakdown']);
        $this->assertCount(1, $data['tax_breakdown']);
        $this->assertSame('Tax (21%)', $data['tax_breakdown'][0]['label']);
        $this->assertSame(21.0, $data['tax_breakdown'][0]['amount']);

        $this->assertCount(1, $data['payments']);
        $this->assertSame('cash', $data['payments'][0]['method_code']);
        $this->assertSame(120.0, $data['payments'][0]['amount']);
        $this->assertSame(4.0, $data['change_due']);
        $this->assertSame('EUR 4.00', $data['change_due_formatted']);

        $this->assertSame('EUR', $data['currency_code']);
        $this->assertSame("\u{20AC}", $data['currency_symbol']);
    }

    public function testBalancedTotalsShowNoShippingOrOtherCharges(): void
    {
        $service = $this->makeService();
        $this->givenPosOrderExists();

        $totals = $service->getReceiptData(self::ORDER_ID)['totals'];

        $this->assertSame('', $totals['shipping_formatted']);
        $this->assertSame(0.0, $totals['adjustment']);
        $this->assertSame('', $totals['adjustment_formatted']);
    }

    public function testShippingAndOtherChargesKeepTheReceiptBalanced(): void
    {
        $this->grandTotal = 126.25;
        $this->shippingAmount = 8.0;
        $service = $this->makeService();
        $this->givenPosOrderExists();

        $totals = $service->getReceiptData(self::ORDER_ID)['totals'];

        $this->assertSame('EUR 8.00', $totals['shipping_formatted']);
        $this->assertSame(2.25, $totals['adjustment']);
        $this->assertSame('EUR 2.25', $totals['adjustment_formatted']);
    }

    public function testNegativeOtherChargesAreShownAsCredit(): void
    {
        $this->grandTotal = 110.0;
        $service = $this->makeService();
        $this->givenPosOrderExists();

        $totals = $service->getReceiptData(self::ORDER_ID)['totals'];

        $this->assertSame(-6.0, $totals['adjustment']);
        $this->assertSame('-EUR 6.00', $totals['adjustment_formatted']);
    }

    public function testGetReceiptDataThrowsWhenOrderWasNotPlacedViaPos(): void
    {
        $service = $this->makeService();

        $posOrder = $this->createMock(PosOrder::class);
        $posOrder->method('getId')->willReturn(null);
        $collection = $this->createMock(PosOrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($posOrder);
        $this->posOrderCollectionFactory->method('create')->willReturn($collection);

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('No POS receipt exists for order id "10".');
        $service->getReceiptData(self::ORDER_ID);
    }

    public function testEmailRejectsInvalidRecipientBeforeLoadingAnything(): void
    {
        $service = $this->makeService();
        $this->orderRepository->expects($this->never())->method('get');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Please provide a valid receipt email address.');
        $service->email(self::ORDER_ID, 'not-an-email');
    }

    private function givenPosOrderExists(): void
    {
        $posOrder = $this->createMock(PosOrder::class);
        $posOrder->method('getId')->willReturn(5);
        $posOrder->method('getPosOrderId')->willReturn(5);
        $posOrder->method('getReceiptNumber')->willReturn('main-12-3');
        $posOrder->method('getRegisterId')->willReturn(3);
        $posOrder->method('getPosUserId')->willReturn(9);

        $collection = $this->createMock(PosOrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($posOrder);
        $this->posOrderCollectionFactory->method('create')->willReturn($collection);

        $register = $this->createMock(RegisterInterface::class);
        $register->method('getName')->willReturn('Main Register');
        $register->method('getCode')->willReturn('main');
        $register->method('getReceiptHeader')->willReturn(null);
        $register->method('getReceiptFooter')->willReturn('  ');
        $this->registerRepository->method('getById')->with(3)->willReturn($register);

        $cashier = $this->createMock(PosUserInterface::class);
        $cashier->method('getName')->willReturn('Alice');
        $cashier->method('getUsername')->willReturn('alice');
        $this->posUserRepository->method('getById')->with(9)->willReturn($cashier);

        $tender = $this->createMock(PosOrderPayment::class);
        $tender->method('getIsChange')->willReturn(0);
        $tender->method('getMethodCode')->willReturn('cash');
        $tender->method('getMethodTitle')->willReturn('Cash');
        $tender->method('getAmount')->willReturn(120.0);
        $tender->method('getReference')->willReturn(null);
        $change = $this->createMock(PosOrderPayment::class);
        $change->method('getIsChange')->willReturn(1);
        $change->method('getAmount')->willReturn(-4.0);

        $paymentCollection = $this->createMock(PosOrderPaymentCollection::class);
        $paymentCollection->method('addFieldToFilter')->willReturnSelf();
        $paymentCollection->method('setOrder')->willReturnSelf();
        $paymentCollection->method('getIterator')->willReturn(new \ArrayIterator([$tender, $change]));
        $this->posOrderPaymentCollectionFactory->method('create')->willReturn($paymentCollection);
    }

    private function makeOrder(): Order&MockObject
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getItemId')->willReturn(77);
        $item->method('getSku')->willReturn('POS-CUSTOM-SALE');
        $item->method('getName')->willReturn('Custom Sale');
        $item->method('getQtyOrdered')->willReturn(2.0);
        $item->method('getPriceInclTax')->willReturn(58.0);
        $item->method('getOriginalPrice')->willReturn(60.0);
        $item->method('getRowTotalInclTax')->willReturn(116.0);
        $item->method('getDiscountAmount')->willReturn(0.0);
        $item->method('getTaxAmount')->willReturn(21.0);
        $item->method('getTaxPercent')->willReturn(21.0);
        $item->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => $key === 'pos_note' ? 'engraved' : null
        );

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(self::ORDER_ID);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getAllVisibleItems')->willReturn([$item]);
        $order->method('getDiscountAmount')->willReturn(-5.0);
        $order->method('getSubtotal')->willReturn(100.0);
        $order->method('getTaxAmount')->willReturn(21.0);
        $order->method('getGrandTotal')->willReturnCallback(fn () => $this->grandTotal);
        $order->method('getShippingAmount')->willReturnCallback(fn () => $this->shippingAmount);
        $order->method('getTotalQtyOrdered')->willReturn(2.0);
        $order->method('getCustomerNote')->willReturn(null);
        $order->method('getCustomerEmail')->willReturn(self::GUEST_EMAIL);
        $order->method('getCustomerFirstname')->willReturn('POS');
        $order->method('getCustomerLastname')->willReturn('Customer');
        $order->method('getCreatedAt')->willReturn('2026-06-11 10:00:00');

        return $order;
    }

    private function makeService(): ReceiptService
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->method('get')->with(self::ORDER_ID)->willReturn($this->makeOrder());

        $this->posOrderCollectionFactory = $this->createMock(PosOrderCollectionFactory::class);
        $this->posOrderPaymentCollectionFactory = $this->createMock(PosOrderPaymentCollectionFactory::class);
        $this->registerRepository = $this->createMock(RegisterRepositoryInterface::class);
        $this->posUserRepository = $this->createMock(PosUserRepositoryInterface::class);

        $config = $this->createMock(Config::class);
        $config->method('getCustomProductSku')->willReturn('pos-custom-sale');
        $config->method('getGuestEmail')->willReturn(self::GUEST_EMAIL);
        $config->method('isShowTaxBreakdown')->willReturn(true);
        $config->method('getReceiptHeader')->willReturn('Welcome!');
        $config->method('getReceiptFooter')->willReturn('Thank you for your purchase!');
        $config->method('getReceiptLogo')->willReturn('');

        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn ($path) => $path === 'general/store_information/name' ? 'Acme Store' : null
        );

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(
            static fn ($amount) => 'EUR ' . number_format((float)$amount, 2, '.', '')
        );
        $priceCurrency->method('getCurrencySymbol')->willReturn("\u{20AC}");

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('formatDateTime')->willReturn('Jun 11, 2026, 10:00 AM');

        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn ($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );

        return new ReceiptService(
            $this->orderRepository,
            $this->posOrderCollectionFactory,
            $this->posOrderPaymentCollectionFactory,
            $this->registerRepository,
            $this->posUserRepository,
            $config,
            $this->scopeConfig,
            $this->createMock(StoreManagerInterface::class),
            $priceCurrency,
            $timezone,
            $this->createMock(BlockFactory::class),
            $this->createMock(TransportBuilder::class),
            $this->createMock(Emulation::class),
            $escaper
        );
    }
}
