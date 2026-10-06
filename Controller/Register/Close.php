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

class Close extends AbstractPosController implements HttpPostActionInterface
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
            $this->auth->requirePermission('can_open_close');

            $current = $this->posSessionService->current();
            if ($current === null) {
                throw new LocalizedException(__('There is no open register session to close.'));
            }

            $countedCash = (float)$this->getRequestValue('counted_cash');
            $note = $this->getRequestValue('note');
            $note = $note !== null && $note !== '' ? (string)$note : null;

            return $this->jsonSuccess(
                $this->posSessionService->close((int)$current['session_id'], $countedCash, $note)
            );
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('[Panth_MagePos] register/close failed: ' . $e->getMessage(), ['exception' => $e]);

            return $this->jsonError((string)__('Unable to close the register session.'));
        }
    }
}
