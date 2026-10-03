<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Terminal;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Element\BlockFactory;
use Panth\MagePos\Block\Terminal;
use Panth\MagePos\Helper\Config;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly ResultFactory $resultFactory,
        private readonly BlockFactory $blockFactory,
        private readonly Config $config
    ) {
    }

    public function execute(): Raw
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        $result->setHeader('X-Robots-Tag', 'noindex, nofollow', true);

        if (!$this->config->isEnabled()) {
            $result->setHttpResponseCode(404);
            $result->setContents($this->renderDisabledPage());

            return $result;
        }

        $block = $this->blockFactory->createBlock(Terminal::class);
        $result->setContents($block->toHtml());

        return $result;
    }

    private function renderDisabledPage(): string
    {
        $title = (string)__('Point of Sale');
        $message = (string)__('The POS terminal is currently disabled.');

        return '<!DOCTYPE html>'
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;'
            . 'justify-content:center;min-height:100vh;margin:0;background:#f4f5f7;color:#333}'
            . '.box{text-align:center;padding:2rem}</style></head>'
            . '<body><div class="box"><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p></div></body></html>';
    }
}
