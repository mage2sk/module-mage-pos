<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Role;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Panth\MagePos\Api\RoleRepositoryInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::roles';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly RoleRepositoryInterface $roleRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $id = (int) $this->getRequest()->getParam('id');
        if ($id > 0) {
            try {
                $this->roleRepository->getById($id);
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This POS role no longer exists.'));

                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_MagePos::roles');
        $page->getConfig()->getTitle()->prepend(
            $id > 0 ? __('Edit POS Role') : __('New POS Role')
        );

        return $page;
    }
}
