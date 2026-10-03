<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Catalog;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CatalogService;

class Categories extends AbstractPosController implements HttpGetActionInterface
{
    private readonly AuthService $auth;

    public function __construct(
        JsonFactory $resultJsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly CatalogService $catalogService,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($resultJsonFactory, $formKeyValidator, $authService, $request, $config);
        $this->auth = $authService;
    }

    public function execute(): ResultInterface
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        try {
            $this->auth->requireUser();
        } catch (LocalizedException $e) {
            return $this->jsonError('unauthorized', 'unauthorized');
        }

        try {
            $storeId = (int)$this->storeManager->getStore()->getId();

            return $this->jsonSuccess($this->catalogService->categoryTree($storeId));
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            return $this->jsonError((string)__('Could not load categories. Please try again.'));
        }
    }
}
