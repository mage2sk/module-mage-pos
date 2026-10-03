<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Register;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\PosSessionService;
use Psr\Log\LoggerInterface;

class Movement extends AbstractPosController implements HttpPostActionInterface
{
    private readonly AuthService $auth;
    private readonly FormKeyValidator $formKey;
    private readonly RequestInterface $httpRequest;

    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly PosSessionService $posSessionService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($jsonFactory, $formKeyValidator, $authService, $request, $config);
        $this->auth = $authService;
        $this->formKey = $formKeyValidator;
        $this->httpRequest = $request;
    }

    public function execute()
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        if ($invalid = $this->checkFormKey()) {
            return $invalid;
        }

        try {
            $this->auth->requireUser();
        } catch (LocalizedException $e) {
            return $this->jsonError('unauthorized', 'unauthorized');
        }

        try {
            $this->auth->requirePermission('can_cash_inout');

            $type = (string)$this->getRequestValue('type');
            $amount = (float)$this->getRequestValue('amount');
            $reason = trim((string)$this->getRequestValue('reason'));

            return $this->jsonSuccess($this->posSessionService->addMovement($type, $amount, $reason));
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('[Panth_MagePos] register/movement failed: ' . $e->getMessage(), ['exception' => $e]);

            return $this->jsonError((string)__('Unable to record the cash movement.'));
        }
    }
}
