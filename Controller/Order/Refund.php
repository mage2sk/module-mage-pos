<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Order;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\RefundService;
use Psr\Log\LoggerInterface;

class Refund extends AbstractPosController implements HttpPostActionInterface
{
    private readonly JsonFactory $posJsonFactory;
    private readonly FormKeyValidator $posFormKeyValidator;
    private readonly AuthService $posAuthService;
    private readonly RequestInterface $posRequest;

    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly RefundService $refundService,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($jsonFactory, $formKeyValidator, $authService, $request, $config);
        $this->posJsonFactory = $jsonFactory;
        $this->posFormKeyValidator = $formKeyValidator;
        $this->posAuthService = $authService;
        $this->posRequest = $request;
    }

    public function execute()
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        $data = $this->getRequestData();

        if (!$this->isFormKeyValid($data)) {
            return $this->createFormKeyErrorResult();
        }

        try {
            $this->posAuthService->requireUser();
        } catch (LocalizedException $e) {
            return $this->jsonError('unauthorized', 'unauthorized');
        }

        try {
            $orderId = (int)($data['order_id'] ?? 0);
            if ($orderId <= 0) {
                return $this->jsonError((string)__('order_id is required.'));
            }
            $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
            $payments = isset($data['payments']) && is_array($data['payments'])
                ? array_values($data['payments'])
                : [];
            $restock = filter_var($data['restock'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $reason = trim((string)($data['reason'] ?? ''));

            $result = $this->refundService->refund($orderId, $items, $payments, $restock, $reason);

            return $this->jsonSuccess($result);
        } catch (NoSuchEntityException $e) {
            return $this->jsonError((string)__('The requested order does not exist.'));
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Panth_MagePos] Order refund failed: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $this->jsonError((string)__('Unable to process the refund. Please try again.'));
        }
    }

    private function getRequestData(): array
    {
        $params = $this->posRequest->getParams();
        $content = method_exists($this->posRequest, 'getContent')
            ? (string)$this->posRequest->getContent()
            : '';
        if ($content !== '') {
            try {
                $decoded = $this->json->unserialize($content);
                if (is_array($decoded)) {
                    $params = array_merge($decoded, $params);
                }
            } catch (\InvalidArgumentException $e) {
            }
        }

        return $params;
    }

    private function isFormKeyValid(array $data): bool
    {
        if (!$this->posRequest->getParam('form_key') && isset($data['form_key'])) {
            $this->posRequest->setParams(['form_key' => (string)$data['form_key']]);
        }

        return $this->posFormKeyValidator->validate($this->posRequest);
    }

    private function createFormKeyErrorResult()
    {
        $result = $this->posJsonFactory->create();
        $result->setHttpResponseCode(403);
        $result->setData([
            'success' => false,
            'data' => null,
            'message' => (string)__('Invalid form key.'),
            'code' => 'invalid_form_key',
        ]);

        return $result;
    }
}
