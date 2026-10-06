<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Order;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\RefundService;
use Psr\Log\LoggerInterface;

class Preview extends AbstractPosController implements HttpPostActionInterface
{
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
    }

    public function execute()
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        $formKeyError = $this->checkFormKey();
        if ($formKeyError !== null) {
            return $formKeyError;
        }

        $authError = $this->checkAuthenticated();
        if ($authError !== null) {
            return $authError;
        }

        try {
            $orderId = (int)$this->getRequestValue('order_id', 0);
            if ($orderId <= 0) {
                return $this->jsonError((string)__('order_id is required.'));
            }
            $items = $this->getRequestValue('items', []);
            if (!is_array($items)) {
                $items = [];
            }

            return $this->jsonSuccess($this->refundService->preview($orderId, $items));
        } catch (NoSuchEntityException $e) {
            return $this->jsonError((string)__('The requested order does not exist.'));
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Panth_MagePos] Refund preview failed: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $this->jsonError((string)__('Unable to compute the refund preview. Please try again.'));
        }
    }
}
