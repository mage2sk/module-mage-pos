<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Register;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\PosSessionService;
use Psr\Log\LoggerInterface;

class Current extends AbstractPosController implements HttpGetActionInterface
{
    private readonly AuthService $auth;

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
    }

    public function execute()
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
            return $this->jsonSuccess($this->posSessionService->current());
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('[Panth_MagePos] register/current failed: ' . $e->getMessage(), ['exception' => $e]);

            return $this->jsonError((string)__('Unable to load the current register session.'));
        }
    }
}
