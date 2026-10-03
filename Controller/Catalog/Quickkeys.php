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
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CatalogService;

class Quickkeys extends AbstractPosController implements HttpGetActionInterface
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
            return $this->jsonSuccess($this->catalogService->quickKeys($this->resolveRegisterId()));
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            return $this->jsonError((string)__('Could not load quick keys. Please try again.'));
        }
    }

    private function resolveRegisterId(): ?int
    {
        $registerId = (int)$this->httpRequest->getParam('register_id', 0);
        if ($registerId > 0) {
            return $registerId;
        }

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
