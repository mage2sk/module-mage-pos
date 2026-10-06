<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Catalog;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
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

class Options extends AbstractPosController implements HttpGetActionInterface
{
    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly CatalogService $catalogService,
        private readonly StoreManagerInterface $storeManager,
        private readonly SessionManagerInterface $sessionManager,
        private readonly SessionRepositoryInterface $sessionRepository
    ) {
        parent::__construct($jsonFactory, $formKeyValidator, $authService, $request, $config);
    }

    public function execute(): Json
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        if ($unauthorized = $this->checkAuthenticated()) {
            return $unauthorized;
        }

        $productId = (int)$this->request->getParam('id', 0);
        $groupId = max(0, (int)$this->request->getParam('group_id', 0));

        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
            $options = $this->catalogService->productOptions(
                $productId,
                $storeId,
                $groupId,
                $this->resolveRegisterId()
            );
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            return $this->jsonError((string)__('Could not load the product options. Please try again.'));
        }

        if ($options === null) {
            return $this->jsonError((string)__('Product not found.'));
        }

        return $this->jsonSuccess($options);
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
