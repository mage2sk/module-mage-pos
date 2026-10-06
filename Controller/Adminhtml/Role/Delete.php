<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Role;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Api\RoleRepositoryInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::roles';

    public function __construct(
        Context $context,
        private readonly RoleRepositoryInterface $roleRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $id = (int) $this->getRequest()->getParam('id');

        if ($id <= 0) {
            $this->messageManager->addErrorMessage(__('We can\'t find a POS role to delete.'));

            return $resultRedirect->setPath('*/*/');
        }

        try {
            $this->roleRepository->deleteById($id);
            $this->messageManager->addSuccessMessage(__('The POS role has been deleted.'));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This POS role no longer exists.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $resultRedirect->setPath('*/*/');
    }
}
