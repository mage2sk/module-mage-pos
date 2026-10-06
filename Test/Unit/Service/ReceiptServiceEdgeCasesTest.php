<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\BlockFactory;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Model\ResourceModel\PosOrder\CollectionFactory as PosOrderCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\CollectionFactory as PosOrderPaymentCollectionFactory;
use Panth\MagePos\Service\ReceiptService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ReceiptServiceEdgeCasesTest extends TestCase
{
    private BlockFactory&MockObject $blockFactory;
    private TransportBuilder&MockObject $transportBuilder;
    private Emulation&MockObject $emulation;
    private array $templateVars = [];
    private array $recipients = [];

    protected function setUp(): void
    {
        $this->templateVars = [];
        $this->recipients = [];
        $this->blockFactory = $this->createMock(BlockFactory::class);
        $this->transportBuilder = $this->createMock(TransportBuilder::class);
        $this->emulation = $this->createMock(Emulation::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setFromByScope'] as $method) {
            $this->transportBuilder->method($method)->willReturnSelf();
        }
        $this->transportBuilder->method('setTemplateVars')->willReturnCallback(function ($vars) {
            $this->templateVars = $vars;
            return $this->transportBuilder;
        });
        $this->transportBuilder->method('addTo')->willReturnCallback(function ($email, $name) {
            $this->recipients[] = [$email, $name];
            return $this->transportBuilder;
        });
    }

    private function makeService(array $receiptData): ReceiptService&MockObject
    {
        $service = $this->getMockBuilder(ReceiptService::class)
            ->setConstructorArgs([
                $this->createStub(OrderRepositoryInterface::class),
                $this->createStub(PosOrderCollectionFactory::class),
                $this->createStub(PosOrderPaymentCollectionFactory::class),
                $this->createStub(RegisterRepositoryInterface::class),
                $this->createStub(PosUserRepositoryInterface::class),
                $this->createStub(Config::class),
                $this->createStub(ScopeConfigInterface::class),
                $this->createStub(StoreManagerInterface::class),
                $this->createStub(PriceCurrencyInterface::class),
                $this->createStub(TimezoneInterface::class),
                $this->blockFactory,
                $this->transportBuilder,
                $this->emulation,
                $this->createStub(Escaper::class),
            ])
            ->onlyMethods(['getReceiptData'])
            ->getMock();
        $service->method('getReceiptData')->willReturn($receiptData);

        return $service;
    }

    public function testRenderHtmlPassesReceiptToTemplateBlock(): void
    {
        $data = ['order_id' => 5, 'items' => []];
        $block = $this->createMock(Template::class);
        $block->expects($this->once())->method('setTemplate')->with('Panth_MagePos::receipt.phtml')->willReturnSelf();
        $block->expects($this->once())->method('setData')->with('receipt', $data)->willReturnSelf();
        $block->method('toHtml')->willReturn('<div>receipt</div>');
        $this->blockFactory->expects($this->once())->method('createBlock')->with(Template::class)->willReturn($block);

        $this->assertSame('<div>receipt</div>', $this->makeService($data)->renderHtml(5));
    }

    public function testEmailSendsTemplateWithinStoreEmulation(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())->method('sendMessage');
        $this->transportBuilder->method('getTransport')->willReturn($transport);
        $this->emulation->expects($this->once())->method('startEnvironmentEmulation')->with(2, 'frontend', true);
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');

        $this->makeService(['store_id' => '2', 'logo_url' => null, 'customer_name' => 'Ann', 'increment_id' => '100'])
            ->email(5, ' ann@x.test ');

        $this->assertSame([['ann@x.test', 'Ann']], $this->recipients);
        $this->assertSame('', $this->templateVars['receipt_logo_url']);
        $this->assertArrayNotHasKey('logo_url', $this->templateVars);
        $this->assertSame('100', $this->templateVars['increment_id']);
    }

    public function testEmailFallsBackToAddressAsRecipientName(): void
    {
        $this->transportBuilder->method('getTransport')->willReturn($this->createStub(TransportInterface::class));

        $this->makeService(['store_id' => 1, 'logo_url' => 'l.png', 'customer_name' => ''])->email(5, 'a@b.co');

        $this->assertSame([['a@b.co', 'a@b.co']], $this->recipients);
        $this->assertSame('l.png', $this->templateVars['receipt_logo_url']);
    }

    public function testMailFailureIsWrappedAndEmulationStopped(): void
    {
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('sendMessage')->willThrowException(new MailException(__('SMTP down')));
        $this->transportBuilder->method('getTransport')->willReturn($transport);
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unable to send the receipt email: SMTP down');
        $this->makeService(['store_id' => 1, 'logo_url' => '', 'customer_name' => 'Ann'])->email(5, 'a@b.co');
    }
}
