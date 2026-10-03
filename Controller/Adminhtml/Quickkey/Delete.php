<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Quickkey;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Panth\MagePos\Api\QuickKeyRepositoryInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::quickkeys';

    public function __construct(
        Context $context,
        private readonly QuickKeyRepositoryInterface $quickKeyRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $id = (int) $this->getRequest()->getParam('id');
        $resultRedirect = $this->resultRedirectFactory->create();

        if ($id > 0) {
            try {
                $this->quickKeyRepository->deleteById($id);
                $this->messageManager->addSuccessMessage(__('The POS quick key has been deleted.'));
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        } else {
            $this->messageManager->addErrorMessage(__('We can\'t find a POS quick key to delete.'));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
