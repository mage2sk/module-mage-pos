<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Register;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\PosSessionService;
use Psr\Log\LoggerInterface;

class Xreport extends AbstractPosController implements HttpGetActionInterface
{
    private readonly AuthService $auth;
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
        $this->httpRequest = $request;
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
            $this->auth->requirePermission('can_view_reports');
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage(), 'forbidden', 403);
        }

        try {
            $sessionId = (int)$this->httpRequest->getParam('session_id');
            if ($sessionId <= 0) {
                $current = $this->posSessionService->current();
                if ($current === null) {
                    throw new LocalizedException(__('There is no open register session.'));
                }
                $sessionId = (int)$current['session_id'];
            }

            return $this->jsonSuccess($this->posSessionService->xReport($sessionId));
        } catch (NoSuchEntityException $e) {
            return $this->jsonError((string)__('The requested register session does not exist.'));
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('[Panth_MagePos] register/xreport failed: ' . $e->getMessage(), ['exception' => $e]);

            return $this->jsonError((string)__('Unable to build the X report.'));
        }
    }
}
