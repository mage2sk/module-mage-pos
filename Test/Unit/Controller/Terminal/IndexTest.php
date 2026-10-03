<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Terminal;

require_once __DIR__ . '/../../autoload.php';

use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Element\BlockFactory;
use Panth\MagePos\Block\Terminal;
use Panth\MagePos\Controller\Index\Index as IndexRedirect;
use Panth\MagePos\Controller\Terminal\Index;
use Panth\MagePos\Helper\Config;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    private ?int $code = null;
    private string $contents = '';
    private array $headers = [];

    private function dispatch(bool $enabled, ?BlockFactory $blockFactory = null): void
    {
        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use ($raw) {
            $this->headers[$name] = $value;
            return $raw;
        });
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) {
            $this->code = $code;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($contents) use ($raw) {
            $this->contents = $contents;
            return $raw;
        });
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->expects($this->once())->method('create')->with(ResultFactory::TYPE_RAW)->willReturn($raw);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        (new Index($resultFactory, $blockFactory ?? $this->createStub(BlockFactory::class), $config))->execute();
    }

    public function testDisabledTerminalRendersNoindex404Page(): void
    {
        $blockFactory = $this->createMock(BlockFactory::class);
        $blockFactory->expects($this->never())->method('createBlock');

        $this->dispatch(false, $blockFactory);

        $this->assertSame(404, $this->code);
        $this->assertStringContainsString('The POS terminal is currently disabled.', $this->contents);
        $this->assertSame('noindex, nofollow', $this->headers['X-Robots-Tag']);
    }

    public function testEnabledTerminalRendersBlock(): void
    {
        $block = $this->createStub(Terminal::class);
        $block->method('toHtml')->willReturn('<div id="pos"></div>');
        $blockFactory = $this->createMock(BlockFactory::class);
        $blockFactory->expects($this->once())->method('createBlock')->with(Terminal::class)->willReturn($block);

        $this->dispatch(true, $blockFactory);

        $this->assertNull($this->code);
        $this->assertSame('<div id="pos"></div>', $this->contents);
        $this->assertSame('text/html; charset=UTF-8', $this->headers['Content-Type']);
    }

    public function testIndexRedirectsToTerminal(): void
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())->method('setPath')->with('pos/terminal/index')->willReturnSelf();
        $factory = $this->createStub(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);

        $this->assertSame($redirect, (new IndexRedirect($factory))->execute());
    }
}
