<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Quickkey;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Panth\MagePos\Api\QuickKeyRepositoryInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::quickkeys';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly QuickKeyRepositoryInterface $quickKeyRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $id = (int) $this->getRequest()->getParam('id');

        if ($id > 0) {
            try {
                $this->quickKeyRepository->getById($id);
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This POS quick key no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_MagePos::quickkeys');
        $page->getConfig()->getTitle()->prepend(
            $id ? __('Edit POS Quick Key #%1', $id) : __('New POS Quick Key')
        );
        return $page;
    }
}
