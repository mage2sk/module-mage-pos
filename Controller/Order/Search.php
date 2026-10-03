<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Order;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\RefundService;
use Psr\Log\LoggerInterface;

class Search extends AbstractPosController implements HttpGetActionInterface
{
    public const CODE_FORBIDDEN = 'forbidden';

    private readonly AuthService $posAuthService;
    private readonly RequestInterface $posRequest;

    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly RefundService $refundService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($jsonFactory, $formKeyValidator, $authService, $request, $config);
        $this->posAuthService = $authService;
        $this->posRequest = $request;
    }

    public function execute()
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        try {
            $this->posAuthService->requireUser();
        } catch (LocalizedException $e) {
            return $this->jsonError('unauthorized', self::CODE_UNAUTHORIZED);
        }

        try {
            $this->posAuthService->requirePermission('can_refund');
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage(), self::CODE_FORBIDDEN, 403);
        }

        try {
            $query = (string)$this->posRequest->getParam('q', '');

            return $this->jsonSuccess($this->refundService->searchOrders($query));
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Panth_MagePos] Order search failed: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $this->jsonError((string)__('Unable to search orders. Please try again.'));
        }
    }
}
