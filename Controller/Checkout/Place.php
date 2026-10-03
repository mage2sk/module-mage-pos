<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Checkout;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CheckoutService;
use Panth\MagePos\Service\ReceiptService;
use Psr\Log\LoggerInterface;

class Place extends AbstractPosController implements HttpPostActionInterface
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
        private readonly CheckoutService $checkoutService,
        private readonly ReceiptService $receiptService,
        private readonly OrderRepositoryInterface $orderRepository,
        Config $config,
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
            $quoteId = (int)($data['quote_id'] ?? 0);
            if ($quoteId <= 0) {
                return $this->jsonError((string)__('quote_id is required.'));
            }
            $payments = isset($data['payments']) && is_array($data['payments'])
                ? array_values($data['payments'])
                : [];

            $opts = [];
            if (isset($data['note']) && trim((string)$data['note']) !== '') {
                $opts['note'] = trim((string)$data['note']);
            }
            if (!empty($data['client_uuid'])) {
                $opts['client_uuid'] = (string)$data['client_uuid'];
            }
            if (!empty($data['is_offline_sync'])) {
                $opts['is_offline_sync'] = true;
            }
            if (!empty($data['register_id'])) {
                $opts['register_id'] = (int)$data['register_id'];
            }

            $result = $this->checkoutService->placeOrder($quoteId, $payments, $opts);
            $this->sendReceiptEmail($result, $data);

            return $this->jsonSuccess($result);
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Panth_MagePos] Checkout place failed: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $this->jsonError((string)__('Unable to place the order. Please try again.'));
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

    private function sendReceiptEmail(array $result, array $data): void
    {
        try {
            $orderId = (int)($result['order_id'] ?? 0);
            if ($orderId <= 0) {
                return;
            }
            $explicit = !empty($data['email_receipt']);
            if (!$explicit && !$this->config->isAutoEmailReceipt()) {
                return;
            }
            $to = trim((string)($data['receipt_email'] ?? ''));
            if ($to === '') {
                $order = $this->orderRepository->get($orderId);
                $email = (string)$order->getCustomerEmail();
                if ($email === '' || strcasecmp($email, $this->config->getGuestEmail()) === 0) {
                    return;
                }
                $to = $email;
            }
            $this->receiptService->email($orderId, $to);
        } catch (\Throwable $e) {
            $this->logger->error('[Panth_MagePos] Receipt email failed: ' . $e->getMessage());
        }
    }
}
