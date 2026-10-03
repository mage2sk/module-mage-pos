<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Catalog;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManagerInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CatalogService;

class QuickkeyRemove extends AbstractPosController implements HttpPostActionInterface
{
    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly CatalogService $catalogService,
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
        if ($invalid = $this->checkFormKey()) {
            return $invalid;
        }
        if ($unauthorized = $this->checkAuthenticated()) {
            return $unauthorized;
        }

        try {
            $this->authService->requirePermission('can_edit_layout');
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        }

        $quickKeyId = $this->getRequestValue('quick_key_id');
        $quickKeyId = is_numeric($quickKeyId) ? (int)$quickKeyId : 0;

        try {
            $this->catalogService->quickKeyRemove($quickKeyId, $this->resolveRegisterId());
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            return $this->jsonError((string)__('Could not remove the quick key. Please try again.'));
        }

        return $this->jsonSuccess(['quick_key_id' => $quickKeyId], (string)__('Quick key removed.'));
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
