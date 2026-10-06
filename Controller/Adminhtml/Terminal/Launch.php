<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Terminal;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class Launch extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::terminal';

    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            $store = $this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore();
            $baseUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_LINK);
            $resultRedirect->setUrl(rtrim($baseUrl, '/') . '/pos');
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('Unable to resolve the store URL for the POS terminal: %1', $e->getMessage())
            );
            $resultRedirect->setPath('adminhtml/dashboard');
        }

        return $resultRedirect;
    }
}
