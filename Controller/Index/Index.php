<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    public function execute(): Redirect
    {
        return $this->redirectFactory->create()->setPath('pos/terminal/index');
    }
}
