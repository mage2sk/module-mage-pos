<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Catalog;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CatalogService;

class Products extends AbstractPosController implements HttpGetActionInterface
{
    private readonly AuthService $auth;

    private readonly RequestInterface $httpRequest;

    public function __construct(
        JsonFactory $resultJsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly CatalogService $catalogService,
        private readonly StoreManagerInterface $storeManager,
        private readonly SessionManagerInterface $sessionManager,
        private readonly SessionRepositoryInterface $sessionRepository
    ) {
        parent::__construct($resultJsonFactory, $formKeyValidator, $authService, $request, $config);
        $this->auth = $authService;
        $this->httpRequest = $request;
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
            $categoryId = (int)$this->httpRequest->getParam('category_id', 0);
            if ($categoryId <= 0) {
                return $this->jsonError((string)__('Category id is required.'));
            }
            $page = max(1, (int)$this->httpRequest->getParam('page', 1));
            $groupId = max(0, (int)$this->httpRequest->getParam('group_id', 0));
            $storeId = (int)$this->storeManager->getStore()->getId();
            $registerId = $this->resolveRegisterId();

            return $this->jsonSuccess(
                $this->catalogService->byCategory($categoryId, $storeId, $page, $groupId, $registerId)
            );
        } catch (NoSuchEntityException $e) {
            return $this->jsonError((string)__('Category no longer exists.'), 'not_found');
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            return $this->jsonError((string)__('Could not load products. Please try again.'));
        }
    }

    private function resolveRegisterId(): ?int
    {
        $posSessionId = (int)$this->sessionManager->getData(AuthService::SESSION_KEY_SESSION_ID);
        if ($posSessionId <= 0) {
            return null;
        }
        try {
            return $this->sessionRepository->getById($posSessionId)->getRegisterId();
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }
}
