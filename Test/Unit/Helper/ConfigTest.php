<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Helper;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Panth\MagePos\Helper\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function makeConfig(array $values = [], array $flags = []): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope = 'default', $storeId = null) use ($values) {
                return $values[$path] ?? null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path) use ($flags) {
                return (bool) ($flags[$path] ?? false);
            }
        );
        $context = $this->createStub(Context::class);
        $context->method('getScopeConfig')->willReturn($scopeConfig);

        return new Config($context);
    }

    public function testValuesAreReadInStoreScopeForTheGivenStore(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())->method('getValue')
            ->with('panth_pos/catalog/search_page_size', ScopeInterface::SCOPE_STORE, 4)
            ->willReturn('50');
        $scopeConfig->expects($this->once())->method('isSetFlag')
            ->with('panth_pos/general/enabled', ScopeInterface::SCOPE_STORE, 4)
            ->willReturn(true);
        $context = $this->createStub(Context::class);
        $context->method('getScopeConfig')->willReturn($scopeConfig);
        $config = new Config($context);

        $this->assertSame(50, $config->getSearchPageSize(4));
        $this->assertTrue($config->isEnabled(4));
    }

    public static function flagProvider(): array
    {
        return [
            ['isEnabled', 'panth_pos/general/enabled'],
            ['isShowOutOfStock', 'panth_pos/catalog/show_out_of_stock'],
            ['isAutoInvoiceOffline', 'panth_pos/checkout/auto_invoice_offline'],
            ['isSessionRequired', 'panth_pos/checkout/require_session'],
            ['isShowTaxBreakdown', 'panth_pos/receipt/show_tax_breakdown'],
            ['isAutoEmailReceipt', 'panth_pos/receipt/auto_email'],
            ['isOfflineModeEnabled', 'panth_pos/offline/enabled'],
        ];
    }

    #[DataProvider('flagProvider')]
    public function testFlagsMapToTheirConfigPaths(string $method, string $path): void
    {
        $this->assertTrue($this->makeConfig([], [$path => true])->$method());
        $this->assertFalse($this->makeConfig()->$method());
    }

    public static function defaultsProvider(): array
    {
        return [
            ['getIdleLockMinutes', 5],
            ['getDefaultRegisterId', null],
            ['getBarcodeAttribute', 'sku'],
            ['getSearchPageSize', 20],
            ['getCatalogCacheLimit', 2000],
            ['getGuestEmail', 'pos-guest@example.com'],
            ['getDefaultCustomerGroupId', 1],
            ['getCustomProductSku', 'pos-custom-sale'],
            ['getCustomProductDefaultTaxClassId', 2],
            ['getOrderNotePrefix', 'POS'],
            ['getReceiptLogo', null],
            ['getReceiptHeader', ''],
            ['getReceiptFooter', 'Thank you for your purchase!'],
            ['getSessionAutoCloseHours', 24],
        ];
    }

    #[DataProvider('defaultsProvider')]
    public function testDefaultsApplyWhenNothingIsConfigured(string $method, mixed $expected): void
    {
        $this->assertSame($expected, $this->makeConfig()->$method());
    }

    public static function configuredProvider(): array
    {
        return [
            ['getIdleLockMinutes', 'panth_pos/general/idle_lock_minutes', '15', 15],
            ['getIdleLockMinutes', 'panth_pos/general/idle_lock_minutes', '0', 0],
            ['getIdleLockMinutes', 'panth_pos/general/idle_lock_minutes', '-3', 0],
            ['getDefaultRegisterId', 'panth_pos/general/default_register', '7', 7],
            ['getDefaultRegisterId', 'panth_pos/general/default_register', '0', null],
            ['getDefaultRegisterId', 'panth_pos/general/default_register', '-2', null],
            ['getBarcodeAttribute', 'panth_pos/catalog/barcode_attribute', '  ean  ', 'ean'],
            ['getBarcodeAttribute', 'panth_pos/catalog/barcode_attribute', '   ', 'sku'],
            ['getSearchPageSize', 'panth_pos/catalog/search_page_size', '0', 20],
            ['getSearchPageSize', 'panth_pos/catalog/search_page_size', '-5', 20],
            ['getCatalogCacheLimit', 'panth_pos/catalog/offline_catalog_limit', '500', 500],
            ['getGuestEmail', 'panth_pos/customer/guest_email', ' walkin@shop.test ', 'walkin@shop.test'],
            ['getDefaultCustomerGroupId', 'panth_pos/customer/default_customer_group', '0', 0],
            ['getDefaultCustomerGroupId', 'panth_pos/customer/default_customer_group', '-1', 0],
            ['getDefaultCustomerGroupId', 'panth_pos/customer/default_customer_group', '3', 3],
            ['getCustomProductSku', 'panth_pos/custom_product/sku', ' misc ', 'misc'],
            ['getCustomProductDefaultTaxClassId', 'panth_pos/custom_product/default_tax_class', '0', 0],
            ['getCustomProductDefaultTaxClassId', 'panth_pos/custom_product/default_tax_class', '9', 9],
            ['getOrderNotePrefix', 'panth_pos/checkout/order_note_prefix', 'Till', 'Till'],
            ['getOrderNotePrefix', 'panth_pos/checkout/order_note_prefix', ' ', ' '],
            ['getReceiptLogo', 'panth_pos/receipt/logo', ' logo.png ', 'logo.png'],
            ['getReceiptHeader', 'panth_pos/receipt/header', 'My Shop', 'My Shop'],
            ['getReceiptFooter', 'panth_pos/receipt/footer', 'Bye', 'Bye'],
            ['getSessionAutoCloseHours', 'panth_pos/session/auto_close_hours', '0', 0],
            ['getSessionAutoCloseHours', 'panth_pos/session/auto_close_hours', '-8', 0],
            ['getSessionAutoCloseHours', 'panth_pos/session/auto_close_hours', '12', 12],
        ];
    }

    #[DataProvider('configuredProvider')]
    public function testConfiguredValuesAreNormalised(string $method, string $path, string $raw, mixed $expected): void
    {
        $this->assertSame($expected, $this->makeConfig([$path => $raw])->$method());
    }
}
