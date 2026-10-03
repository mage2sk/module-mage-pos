<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\User;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Panth\MagePos\Api\PosUserRepositoryInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::users';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly PosUserRepositoryInterface $posUserRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $id = (int) $this->getRequest()->getParam('id');
        if ($id > 0) {
            try {
                $this->posUserRepository->getById($id);
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This POS user no longer exists.'));

                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_MagePos::users');
        $page->getConfig()->getTitle()->prepend(
            $id > 0 ? __('Edit POS User') : __('New POS User')
        );

        return $page;
    }
}
