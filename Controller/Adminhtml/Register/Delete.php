<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Register;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Api\RegisterRepositoryInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::registers';

    public function __construct(
        Context $context,
        private readonly RegisterRepositoryInterface $registerRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $id = (int) $this->getRequest()->getParam('register_id');

        if ($id <= 0) {
            $this->messageManager->addErrorMessage(__('We can\'t find a register to delete.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $this->registerRepository->deleteById($id);
            $this->messageManager->addSuccessMessage(__('The register has been deleted.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addExceptionMessage(
                $e,
                __('Something went wrong while deleting the register.')
            );
        }

        return $resultRedirect->setPath('*/*/');
    }
}
