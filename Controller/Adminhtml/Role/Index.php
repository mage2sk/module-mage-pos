<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Role;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::roles';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_MagePos::roles');
        $page->getConfig()->getTitle()->prepend(__('POS Roles'));

        return $page;
    }
}
