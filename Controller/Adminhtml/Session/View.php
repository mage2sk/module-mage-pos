<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Session;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Panth\MagePos\Api\SessionRepositoryInterface;

class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::sessions';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly SessionRepositoryInterface $sessionRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Page|Redirect
    {
        $sessionId = (int)$this->getRequest()->getParam('session_id');

        try {
            $session = $this->sessionRepository->getById($sessionId);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('POS session with id "%1" does not exist.', $sessionId));

            $resultRedirect = $this->resultRedirectFactory->create();

            return $resultRedirect->setPath('*/*/index');
        }

        $resultPage = $this->pageFactory->create();
        $resultPage->setActiveMenu('Panth_MagePos::sessions');
        $resultPage->getConfig()->getTitle()->prepend(
            __('POS Session #%1', (int)$session->getSessionId())
        );

        return $resultPage;
    }
}
