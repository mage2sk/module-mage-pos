<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Block;

require_once __DIR__ . '/../autoload.php';

use Magento\Directory\Model\Currency;
use Magento\Framework\Currency as LocaleCurrencyModel;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Locale\CurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Block\Terminal;
use Panth\MagePos\Helper\Config;
use PHPUnit\Framework\TestCase;

class TerminalTest extends TestCase
{
    private function makeBlock(?object $store, string|\Throwable $symbol = '$', bool $storeFails = false): Terminal
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($storeFails) {
            $storeManager->method('getStore')->willThrowException(new \Exception('no store'));
        } else {
            $storeManager->method('getStore')->willReturn($store);
        }
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(static fn ($route) => 'https://shop.test/' . $route . '/');
        $context = $this->createStub(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $config = $this->createStub(Config::class);
        $config->method('getIdleLockMinutes')->willReturn(7);
        $config->method('isOfflineModeEnabled')->willReturn(true);
        $formKey = $this->createStub(FormKey::class);
        $formKey->method('getFormKey')->willReturn('fk123');
        $currency = $this->createStub(LocaleCurrencyModel::class);
        if ($symbol instanceof \Throwable) {
            $currency->method('getSymbol')->willThrowException($symbol);
        } else {
            $currency->method('getSymbol')->willReturn($symbol);
        }
        $localeCurrency = $this->createStub(CurrencyInterface::class);
        $localeCurrency->method('getCurrency')->willReturn($currency);

        return new Terminal($context, $config, $formKey, $localeCurrency, new Json());
    }

    private function makeStore(string $code = 'EUR', string $frontendName = 'Shop Front'): Store
    {
        $currency = $this->createStub(Currency::class);
        $currency->method('getCode')->willReturn($code);
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $store->method('getCurrentCurrency')->willReturn($currency);
        $store->method('getFrontendName')->willReturn($frontendName);
        $store->method('getName')->willReturn('Default Store View');

        return $store;
    }

    public function testDefaultTemplateIsAssigned(): void
    {
        $this->assertSame('Panth_MagePos::terminal.phtml', $this->makeBlock($this->makeStore())->getTemplate());
    }

    public function testConfigJsonExposesTerminalSettings(): void
    {
        $config = json_decode($this->makeBlock($this->makeStore(), "\u{20ac}")->getConfigJson(), true);

        $this->assertSame('https://shop.test/pos/', $config['api_base_url']);
        $this->assertSame('fk123', $config['form_key']);
        $this->assertSame("\u{20ac}", $config['currency_symbol']);
        $this->assertSame('EUR', $config['currency_code']);
        $this->assertSame('Shop Front', $config['store_name']);
        $this->assertSame(3, $config['store_id']);
        $this->assertSame(7, $config['idle_lock_minutes']);
        $this->assertTrue($config['offline_enabled']);
        $this->assertSame('1.0.0', $config['version']);
        $this->assertSame('Sign in', $config['i18n']['sign_in']);
        $this->assertGreaterThan(50, count($config['i18n']));
    }

    public function testCurrencySymbolFallsBackToCode(): void
    {
        $this->assertSame('EUR', $this->makeBlock($this->makeStore(), '')->getCurrencySymbol());
        $this->assertSame('EUR', $this->makeBlock($this->makeStore(), new \Exception('x'))->getCurrencySymbol());
    }

    public function testStoreNameFallsBackToStoreName(): void
    {
        $this->assertSame('Default Store View', $this->makeBlock($this->makeStore('EUR', ''))->getStoreName());

        $plainStore = $this->createStub(StoreInterface::class);
        $plainStore->method('getName')->willReturn('Plain');
        $block = $this->makeBlock($plainStore);
        $this->assertSame('Plain', $block->getStoreName());
        $this->assertSame('', $block->getCurrencyCode());
        $this->assertSame('', $block->getCurrencySymbol());
    }

    public function testStoreFailuresDegradeGracefully(): void
    {
        $block = $this->makeBlock(null, '$', true);

        $this->assertSame('', $block->getStoreName());
        $this->assertSame('', $block->getCurrencyCode());
        $config = json_decode($block->getConfigJson(), true);
        $this->assertNull($config['store_id']);
    }
}
