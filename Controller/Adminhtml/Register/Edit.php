<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Register;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Panth\MagePos\Api\RegisterRepositoryInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::registers';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly RegisterRepositoryInterface $registerRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $id = (int) $this->getRequest()->getParam('register_id');
        $title = __('New Register');

        if ($id > 0) {
            try {
                $register = $this->registerRepository->getById($id);
                $title = __('Edit Register "%1"', $register->getName());
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This register no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_MagePos::registers');
        $page->getConfig()->getTitle()->prepend(__('POS Registers'));
        $page->getConfig()->getTitle()->prepend($title);
        return $page;
    }
}
